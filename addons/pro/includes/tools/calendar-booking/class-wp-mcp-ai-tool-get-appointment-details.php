<?php
/**
 * Get Appointment Details Tool
 *
 * Retrieves comprehensive appointment information including
 * client details, status, history, and related metadata.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since 2.6.0
 * @phase Phase 2.6 - Calendar Booking Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for retrieving appointment details.
 *
 * Features:
 * - Complete appointment information
 * - Client contact details
 * - Status and history tracking
 * - Related metadata
 * - Activity log retrieval
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Tool_Get_Appointment_Details implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Get appointment details tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'get_appointment_details';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Appointment Details', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Retrieve comprehensive appointment information including client details, status, history, and metadata.', 'mcp-ai-wpoos-pro' );
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
				'appointment_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Appointment ID to retrieve (required)', 'mcp-ai-wpoos-pro' ),
				),
				'include_history' => array(
					'type'        => 'boolean',
					'description' => __( 'Include change and activity history', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'include_metadata' => array(
					'type'        => 'boolean',
					'description' => __( 'Include custom metadata fields', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'appointment_id' ),
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
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view appointment details.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_available',
				self::get_unavailable_reason()
			);
		}

		// Validate appointment ID.
		if ( empty( $arguments['appointment_id'] ) ) {
			return new WP_Error(
				'missing_appointment_id',
				__( 'Appointment ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$appointment_id = absint( $arguments['appointment_id'] );
		$appointment    = get_post( $appointment_id );

		if ( ! $appointment || 'mcp_appointment' !== $appointment->post_type ) {
			return new WP_Error(
				'invalid_appointment',
				__( 'Invalid appointment ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build appointment details.
		$details = array(
			'id'               => $appointment_id,
			'title'            => get_the_title( $appointment_id ),
			'client'           => array(
				'name'  => get_post_meta( $appointment_id, '_client_name', true ),
				'email' => get_post_meta( $appointment_id, '_client_email', true ),
				'phone' => get_post_meta( $appointment_id, '_client_phone', true ),
			),
			'appointment'      => array(
				'type'       => get_post_meta( $appointment_id, '_appointment_type', true ),
				'start_time' => get_post_meta( $appointment_id, '_start_time', true ),
				'end_time'   => get_post_meta( $appointment_id, '_end_time', true ),
				'location'   => get_post_meta( $appointment_id, '_location', true ),
				'status'     => get_post_meta( $appointment_id, '_status', true ),
			),
			'notes'            => $appointment->post_content,
			'created_at'       => get_post_meta( $appointment_id, '_created_at', true ),
			'created_by'       => get_post_meta( $appointment_id, '_created_by', true ),
		);

		// Include history if requested.
		if ( ! empty( $arguments['include_history'] ) ) {
			$details['history'] = array(
				'activity_log'   => get_post_meta( $appointment_id, '_activity_log', true ) ?: array(),
				'change_history' => get_post_meta( $appointment_id, '_change_history', true ) ?: array(),
			);

			// Add reschedule information if applicable.
			$rescheduled_at = get_post_meta( $appointment_id, '_rescheduled_at', true );
			if ( $rescheduled_at ) {
				$details['history']['rescheduled'] = array(
					'at'     => $rescheduled_at,
					'by'     => get_post_meta( $appointment_id, '_rescheduled_by', true ),
					'reason' => get_post_meta( $appointment_id, '_reschedule_reason', true ),
				);
			}

			// Add cancellation information if applicable.
			$cancelled_at = get_post_meta( $appointment_id, '_cancelled_at', true );
			if ( $cancelled_at ) {
				$details['history']['cancelled'] = array(
					'at'              => $cancelled_at,
					'by'              => get_post_meta( $appointment_id, '_cancelled_by', true ),
					'by_user_id'      => get_post_meta( $appointment_id, '_cancelled_by_user_id', true ),
					'reason'          => get_post_meta( $appointment_id, '_cancellation_reason', true ),
					'refund_processed' => get_post_meta( $appointment_id, '_refund_processed', true ),
				);
			}
		}

		// Include custom metadata if requested.
		if ( ! empty( $arguments['include_metadata'] ) ) {
			$all_meta = get_post_meta( $appointment_id );
			$custom_meta = array();

			foreach ( $all_meta as $key => $value ) {
				if ( strpos( $key, '_custom_' ) === 0 ) {
					$clean_key = str_replace( '_custom_', '', $key );
					$custom_meta[ $clean_key ] = is_array( $value ) && count( $value ) === 1 ? $value[0] : $value;
				}
			}

			if ( ! empty( $custom_meta ) ) {
				$details['custom_metadata'] = $custom_meta;
			}
		}

		return array(
			'success' => true,
			'appointment' => $details,
		);
	}
}
