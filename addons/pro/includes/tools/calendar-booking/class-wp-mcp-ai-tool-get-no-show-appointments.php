<?php
/**
 * Tool for retrieving no-show appointments.
 *
 * Queries the mcp_appointment CPT for appointments marked as no-show,
 * with optional date range and service type filters.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since 2.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves a list of appointments marked as no-show.
 *
 * Supports optional filtering by date range (date_from/date_to),
 * service type, and a configurable result limit.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Tool_Get_No_Show_Appointments implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_no_show_appointments';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get No-Show Appointments', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves a list of appointments marked as no-show, with optional date range and service filters. Useful for identifying missed appointments and planning reschedule outreach.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'date_from'    => array(
					'type'        => 'string',
					'description' => __( 'Filter appointments on or after this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'date_to'      => array(
					'type'        => 'string',
					'description' => __( 'Filter appointments on or before this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'service_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by service type slug (optional).', 'mcp-ai-wpoos-pro' ),
				),
				'limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return. Default: 50.', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 500,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'calendar_booking',
			'post_type'             => 'mcp_appointment',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'coordinator', 'receptionist' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
			'cacheable',
			'phase-2.9',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Calendar Booking toolkit to be enabled in plugin settings.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.9.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Get No-Show Appointments tool requires the Calendar Booking toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list appointments.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() );
		}

		// Parse arguments.
		$date_from    = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to      = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '';
		$service_type = isset( $arguments['service_type'] ) ? sanitize_text_field( $arguments['service_type'] ) : '';
		$limit        = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 500 ) : 50;

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_appointment',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'meta_value',
			'meta_key'       => '_appointment_date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);

		$meta_query = array(
			array(
				'key'     => '_appointment_status',
				'value'   => 'no_show',
				'compare' => '=',
			),
		);

		// Filter by date range.
		if ( ! empty( $date_from ) ) {
			$meta_query[] = array(
				'key'     => '_appointment_date',
				'value'   => $date_from,
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}

		if ( ! empty( $date_to ) ) {
			$meta_query[] = array(
				'key'     => '_appointment_date',
				'value'   => $date_to,
				'compare' => '<=',
				'type'    => 'DATE',
			);
		}

		// Filter by service type.
		if ( ! empty( $service_type ) ) {
			$meta_query[] = array(
				'key'     => '_service_type',
				'value'   => $service_type,
				'compare' => '=',
			);
		}

		$query_args['meta_query'] = $meta_query;

		$query        = new WP_Query( $query_args );
		$appointments = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$appointment_id = get_the_ID();

				$appointments[] = array(
					'id'               => $appointment_id,
					'client_name'      => get_post_meta( $appointment_id, '_client_name', true ) ? get_post_meta( $appointment_id, '_client_name', true ) : '',
					'client_email'     => get_post_meta( $appointment_id, '_client_email', true ) ? get_post_meta( $appointment_id, '_client_email', true ) : '',
					'appointment_date' => get_post_meta( $appointment_id, '_appointment_date', true ) ? get_post_meta( $appointment_id, '_appointment_date', true ) : '',
					'start_time'       => get_post_meta( $appointment_id, '_start_time', true ) ? get_post_meta( $appointment_id, '_start_time', true ) : '',
					'end_time'         => get_post_meta( $appointment_id, '_end_time', true ) ? get_post_meta( $appointment_id, '_end_time', true ) : '',
					'service_type'     => get_post_meta( $appointment_id, '_service_type', true ) ? get_post_meta( $appointment_id, '_service_type', true ) : '',
					'status'           => 'no_show',
					'notes'            => get_post_meta( $appointment_id, '_appointment_notes', true ) ? get_post_meta( $appointment_id, '_appointment_notes', true ) : '',
					'created_at'       => get_the_date( 'c' ),
					'updated_at'       => get_the_modified_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'      => true,
			'count'        => count( $appointments ),
			'total'        => $query->found_posts,
			'message'      => sprintf(
				/* translators: %d: number of no-show appointments found */
				__( 'Found %d no-show appointments.', 'mcp-ai-wpoos-pro' ),
				$query->found_posts
			),
			'appointments' => $appointments,
		);
	}
}
