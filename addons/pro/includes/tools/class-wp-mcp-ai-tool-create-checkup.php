<?php
/**
 * Tool for creating checkups/appointments.
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
		return __( 'Creates a new checkup or appointment for a member with date, time, provider, and location information.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Member ID this checkup belongs to (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'title'     => array(
					'type'        => 'string',
					'description' => __( 'Checkup title (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'datetime'  => array(
					'type'        => 'string',
					'description' => __( 'Date and time (YYYY-MM-DD HH:MM) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$',
				),
				'provider'  => array(
					'type'        => 'string',
					'description' => __( 'Healthcare provider name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'location'  => array(
					'type'        => 'string',
					'description' => __( 'Location or facility name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'type'      => array(
					'type'        => 'string',
					'description' => __( 'Type of checkup (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'wellness', 'follow-up', 'consultation', 'procedure', 'vaccination', 'dental', 'vision', '' ),
				),
				'status'    => array(
					'type'        => 'string',
					'description' => __( 'Appointment status (optional, defaults to scheduled)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'scheduled', 'completed', 'cancelled', 'no-show' ),
					'default'     => 'scheduled',
				),
				'notes'     => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
			),
			'required'             => array( 'member_id', 'title', 'datetime' ),
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
		$datetime  = isset( $arguments['datetime'] ) ? sanitize_text_field( $arguments['datetime'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $datetime ) {
			return new WP_Error( 'wp_mcp_ai_missing_datetime', __( 'Date and time are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate datetime.
		if ( ! $this->validate_datetime( $datetime ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_datetime', __( 'Invalid datetime format. Use YYYY-MM-DD HH:MM.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize optional fields.
		$provider = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$location = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';
		$type     = isset( $arguments['type'] ) ? sanitize_key( $arguments['type'] ) : '';
		$status   = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'scheduled';
		$notes    = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';

		// Create checkup post.
		$post_data = array(
			'post_type'    => 'mcp_ai_checkup',
			'post_title'   => $title,
			'post_content' => $notes,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$checkup_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $checkup_id ) ) {
			return $checkup_id;
		}

		// Save checkup metadata.
		update_post_meta( $checkup_id, '_checkup_member_id', $member_id );
		update_post_meta( $checkup_id, '_checkup_datetime', $datetime );
		update_post_meta( $checkup_id, '_checkup_status', $status );

		if ( $provider ) {
			update_post_meta( $checkup_id, '_checkup_provider', $provider );
		}

		if ( $location ) {
			update_post_meta( $checkup_id, '_checkup_location', $location );
		}

		if ( $type ) {
			update_post_meta( $checkup_id, '_checkup_type', $type );
		}

		return array(
			'success'    => true,
			'message'    => __( 'Checkup created successfully.', 'mcp-ai-wpoos-pro' ),
			'checkup_id' => $checkup_id,
			'checkup'    => array(
				'id'         => $checkup_id,
				'member_id'  => $member_id,
				'title'      => $title,
				'datetime'   => $datetime,
				'provider'   => $provider,
				'location'   => $location,
				'type'       => $type,
				'status'     => $status,
				'notes'      => $notes,
				'created_at' => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Validate datetime format (YYYY-MM-DD HH:MM).
	 *
	 * @param string $datetime Datetime string.
	 * @return bool
	 */
	private function validate_datetime( $datetime ) {
		$d = DateTime::createFromFormat( 'Y-m-d H:i', $datetime );
		return $d && $d->format( 'Y-m-d H:i' ) === $datetime;
	}
}
