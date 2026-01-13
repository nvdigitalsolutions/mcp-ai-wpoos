<?php
/**
 * Tool for creating checkups/appointments.
 *
 * Allows AI assistants to schedule new checkups/appointments for members.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new checkup/appointment.
 */
class WP_MCP_AI_Tool_Create_Checkup implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_checkup';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Checkup', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Schedules a new checkup or appointment for a member, including date, time, provider, and location details.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this checkup is for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Checkup title or type (e.g., "Annual Physical", "Dental Cleaning") (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'date'        => array(
					'type'        => 'string',
					'description' => __( 'Checkup date (YYYY-MM-DD) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'time'        => array(
					'type'        => 'string',
					'description' => __( 'Checkup time (HH:MM format, optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{2}:\d{2}$',
				),
				'provider'    => array(
					'type'        => 'string',
					'description' => __( 'Healthcare provider name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'location'    => array(
					'type'        => 'string',
					'description' => __( 'Checkup location or facility (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'notes'       => array(
					'type'        => 'string',
					'description' => __( 'Additional notes or instructions (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Checkup status (optional, default: scheduled)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'scheduled', 'completed', 'cancelled', 'no-show' ),
					'default'     => 'scheduled',
				),
			),
			'required' => array(
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create checkups.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$title     = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$date      = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $title ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Checkup title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Valid date (YYYY-MM-DD) is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Optional fields.
		$time     = isset( $arguments['time'] ) ? sanitize_text_field( $arguments['time'] ) : '';
		$provider = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$location = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';
		$notes    = isset( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';
		$status   = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'scheduled';

		// Validate time format if provided.
		if ( ! empty( $time ) && ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_time', __( 'Time must be in HH:MM format.', 'mcp-ai-wpoos-pro' ) );
		}

		// Create the checkup post.
		$checkup_id = wp_insert_post(
		array(
			'post_type'    => 'mcp_ai_checkup',
			'post_title'   => $title,
			'post_content' => $notes,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		),
		true
	);

		if ( is_wp_error( $checkup_id ) ) {
			return $checkup_id;
		}

		// Set checkup metadata.
		update_post_meta( $checkup_id, '_checkup_member_id', $member_id );
		update_post_meta( $checkup_id, '_checkup_date', $date );
		update_post_meta( $checkup_id, '_checkup_status', $status );

		if ( $time ) {
			update_post_meta( $checkup_id, '_checkup_time', $time );
		}
		if ( $provider ) {
			update_post_meta( $checkup_id, '_checkup_provider', $provider );
		}
		if ( $location ) {
			update_post_meta( $checkup_id, '_checkup_location', $location );
		}

		// Build response.
		$checkup_data = array(
			'id'          => $checkup_id,
			'title'       => $title,
			'member_id'   => $member_id,
			'member_name' => $member->post_title,
			'date'        => $date,
			'time'        => $time,
			'provider'    => $provider,
			'location'    => $location,
			'status'      => $status,
			'notes'       => $notes,
		);

		return array(
			'success' => true,
			'checkup' => $checkup_data,
			'message' => sprintf(
				/* translators: 1: checkup title, 2: member name, 3: date */
				__( 'Checkup "%1$s" scheduled for %2$s on %3$s.', 'mcp-ai-wpoos-pro' ),
				$title,
				$member->post_title,
				$date
			),
		);
	}
}
