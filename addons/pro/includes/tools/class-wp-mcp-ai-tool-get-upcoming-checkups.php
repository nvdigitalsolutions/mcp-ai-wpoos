<?php
/**
 * Tool for getting upcoming checkups/appointments.
 *
 * Specialized tool for retrieving upcoming checkups for a member.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets upcoming checkups/appointments for a member.
 */
class WP_MCP_AI_Tool_Get_Upcoming_Checkups implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_upcoming_checkups';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Upcoming Checkups', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves upcoming checkups/appointments for a member within a specified time period (default: next 90 days). Useful for appointment preparation and scheduling.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'days'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of days to look ahead (optional, default: 90, max: 365)', 'mcp-ai-wpoos-pro' ),
					'default'     => 90,
					'minimum'     => 1,
					'maximum'     => 365,
				),
				'limit'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of checkups to return (optional, default: 10, max: 50)', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 50,
				),
			),
			'required'             => array( 'member_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
	}

	/**
	 * Check if the tool is available.
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
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view checkups.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$days      = isset( $arguments['days'] ) ? absint( $arguments['days'] ) : 90;
		$limit     = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate days.
		if ( $days < 1 ) {
			$days = 90;
		}
		if ( $days > 365 ) {
			$days = 365;
		}

		// Validate limit.
		if ( $limit < 1 ) {
			$limit = 10;
		}
		if ( $limit > 50 ) {
			$limit = 50;
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Calculate date range.
		$today        = current_time( 'Y-m-d' );
		$end_date     = gmdate( 'Y-m-d', strtotime( "+{$days} days" ) );

		// Query upcoming checkups.
		$query = new WP_Query( array(
			'post_type'      => 'mcp_ai_checkup',
			'post_status'    => 'publish',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => '_checkup_member_id',
					'value' => $member_id,
				),
				array(
					'key'     => '_checkup_date',
					'value'   => array( $today, $end_date ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
				array(
					'key'     => '_checkup_status',
					'value'   => 'cancelled',
					'compare' => '!=',
				),
			),
			'meta_key'       => '_checkup_date',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'posts_per_page' => $limit,
		) );

		$upcoming_checkups = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$checkup_id = get_the_ID();

				$checkup_date = get_post_meta( $checkup_id, '_checkup_date', true );
				$days_until   = 0;
				if ( $checkup_date ) {
					$date_diff  = strtotime( $checkup_date ) - strtotime( $today );
					$days_until = floor( $date_diff / ( 60 * 60 * 24 ) );
				}

				$upcoming_checkups[] = array(
					'id'         => $checkup_id,
					'title'      => get_the_title(),
					'date'       => $checkup_date,
					'time'       => get_post_meta( $checkup_id, '_checkup_time', true ),
					'provider'   => get_post_meta( $checkup_id, '_checkup_provider', true ),
					'location'   => get_post_meta( $checkup_id, '_checkup_location', true ),
					'status'     => get_post_meta( $checkup_id, '_checkup_status', true ),
					'days_until' => $days_until,
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'           => true,
			'member_id'         => $member_id,
			'member_name'       => $member->post_title,
			'upcoming_checkups' => $upcoming_checkups,
			'count'             => count( $upcoming_checkups ),
			'date_range'        => array(
				'from' => $today,
				'to'   => $end_date,
				'days' => $days,
			),
		);
	}
}
