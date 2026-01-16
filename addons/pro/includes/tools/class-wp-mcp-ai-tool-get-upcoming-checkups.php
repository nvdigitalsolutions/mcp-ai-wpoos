<?php
/**
 * Tool for getting upcoming checkups.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get upcoming checkups/appointments.
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
		return __( 'Retrieves upcoming checkups and appointments for a member within a specified time frame (default: next 90 days).', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Number of days to look ahead (default: 90)', 'mcp-ai-wpoos-pro' ),
					'default'     => 90,
					'minimum'     => 1,
					'maximum'     => 365,
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

		// Validate member ID.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$days      = isset( $arguments['days'] ) ? absint( $arguments['days'] ) : 90;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Calculate date range.
		$now       = current_time( 'Y-m-d H:i' );
		$end_date  = date( 'Y-m-d H:i', strtotime( "+{$days} days", current_time( 'timestamp' ) ) );

		// Build query for upcoming checkups.
		$query_args = array(
			'post_type'      => 'mcp_ai_checkup',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => '_checkup_member_id',
					'value' => $member_id,
				),
				array(
					'key'   => '_checkup_status',
					'value' => 'scheduled',
				),
				array(
					'key'     => '_checkup_datetime',
					'value'   => array( $now, $end_date ),
					'compare' => 'BETWEEN',
					'type'    => 'CHAR',
				),
			),
			'meta_key'       => '_checkup_datetime',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
		);

		$query = new WP_Query( $query_args );

		$checkups = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$checkup_id = get_the_ID();

				$checkups[] = array(
					'id'       => $checkup_id,
					'title'    => get_the_title(),
					'datetime' => get_post_meta( $checkup_id, '_checkup_datetime', true ),
					'provider' => get_post_meta( $checkup_id, '_checkup_provider', true ),
					'location' => get_post_meta( $checkup_id, '_checkup_location', true ),
					'type'     => get_post_meta( $checkup_id, '_checkup_type', true ),
					'notes'    => get_the_content(),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'      => true,
			'member_id'    => $member_id,
			'member_name'  => $member->post_title,
			'checkups'     => $checkups,
			'total'        => count( $checkups ),
			'date_range'   => array(
				'start' => $now,
				'end'   => $end_date,
				'days'  => $days,
			),
		);
	}
}
