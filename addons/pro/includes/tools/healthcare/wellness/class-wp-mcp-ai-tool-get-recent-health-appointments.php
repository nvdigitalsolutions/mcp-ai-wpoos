<?php
/**
 * Tool: get_recent_health_appointments
 *
 * Retrieves recent health/wellness appointments, optionally filtered by
 * member, provider, or appointment type.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get recent health appointments tool.
 */
class WP_MCP_AI_Tool_Get_Recent_Health_Appointments implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Get Recent Health Appointments tool requires the Health & Wellness Management toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_recent_health_appointments';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Recent Health Appointments', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves recent health/wellness appointments, optionally filtered by member, provider, or appointment type.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'member_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Filter by member post ID. Optional.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'provider_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Filter by provider (user) ID. Optional.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'appointment_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by appointment type (e.g. checkup, follow-up, consultation). Optional.', 'mcp-ai-wpoos-pro' ),
				),
				'days_back'        => array(
					'type'        => 'integer',
					'description' => __( 'Number of days to look back. Default: 30.', 'mcp-ai-wpoos-pro' ),
					'default'     => 30,
					'minimum'     => 1,
					'maximum'     => 365,
				),
				'limit'            => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of appointments to return. Default: 50.', 'mcp-ai-wpoos-pro' ),
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
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-capability',
			'pii-data',
			'cacheable',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
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
			'toolkit'               => 'health_wellness_management',
			'pattern_compatibility' => array( 'orchestrator', 'sequential', 'standalone' ),
			'profession_tags'       => array( 'healthcare', 'administrator' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Appointment data.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Parse arguments with sanitization.
		$member_id       = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$provider_id     = isset( $arguments['provider_id'] ) ? absint( $arguments['provider_id'] ) : 0;
		$appointment_type = isset( $arguments['appointment_type'] ) ? sanitize_text_field( $arguments['appointment_type'] ) : '';
		$days_back       = isset( $arguments['days_back'] ) ? absint( $arguments['days_back'] ) : 30;
		$limit           = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;

		// Clamp values.
		$days_back = max( 1, min( 365, $days_back ) );
		$limit     = max( 1, min( 500, $limit ) );

		// Calculate date threshold.
		$date_from = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_back} days" ) );

		// Build query args for checkup/appointment posts.
		$query_args = array(
			'post_type'      => 'mcp_ai_checkup',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'date_query'     => array(
				array(
					'after' => $date_from,
				),
			),
		);

		// Apply filters.
		$meta_query = array();

		if ( $member_id > 0 ) {
			$meta_query[] = array(
				'key'   => '_member_id',
				'value' => $member_id,
				'type'  => 'NUMERIC',
			);
		}

		if ( $provider_id > 0 ) {
			$meta_query[] = array(
				'key'   => '_provider_id',
				'value' => $provider_id,
				'type'  => 'NUMERIC',
			);
		}

		if ( '' !== $appointment_type ) {
			$meta_query[] = array(
				'key'   => '_appointment_type',
				'value' => $appointment_type,
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$posts = get_posts( $query_args );

		$appointments = array();
		foreach ( $posts as $post ) {
			$member_id_val     = get_post_meta( $post->ID, '_member_id', true );
			$provider_id_val   = get_post_meta( $post->ID, '_provider_id', true );
			$appt_type_val     = get_post_meta( $post->ID, '_appointment_type', true );
			$appointment_date  = get_post_meta( $post->ID, '_appointment_date', true );
			$status            = get_post_meta( $post->ID, '_status', true );

			$appointments[] = array(
				'id'               => $post->ID,
				'title'            => esc_html( $post->post_title ),
				'member_id'        => $member_id_val ? absint( $member_id_val ) : 0,
				'provider_id'      => $provider_id_val ? absint( $provider_id_val ) : 0,
				'appointment_type' => esc_html( $appt_type_val ),
				'appointment_date' => esc_html( $appointment_date ),
				'status'           => esc_html( $status ),
				'created_date'     => esc_html( $post->post_date ),
			);
		}

		return array(
			'success'       => true,
			'message'       => sprintf(
				/* translators: %d: number of appointments found */
				__( 'Found %d recent appointments.', 'mcp-ai-wpoos-pro' ),
				count( $appointments )
			),
			'count'         => count( $appointments ),
			'appointments'  => $appointments,
			'filters'       => array(
				'days_back'        => $days_back,
				'member_id'        => $member_id,
				'provider_id'      => $provider_id,
				'appointment_type' => $appointment_type,
			),
		);
	}
}
