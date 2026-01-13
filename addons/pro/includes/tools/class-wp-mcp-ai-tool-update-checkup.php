<?php
/**
 * Tool for updating checkup information.
 *
 * Allows AI assistants to update existing checkups/appointments.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing checkup/appointment.
 */
class WP_MCP_AI_Tool_Update_Checkup implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_checkup';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Checkup', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing checkup/appointment. Only the checkup creator or users with edit_others_posts capability can update checkups.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'checkup_id' => array(
					'type'        => 'integer',
					'description' => __( 'Checkup ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'title'      => array(
					'type'        => 'string',
					'description' => __( 'Checkup title or type (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'date'       => array(
					'type'        => 'string',
					'description' => __( 'Checkup date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'time'       => array(
					'type'        => 'string',
					'description' => __( 'Checkup time (HH:MM format) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{2}:\d{2}$',
				),
				'provider'   => array(
					'type'        => 'string',
					'description' => __( 'Healthcare provider name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'location'   => array(
					'type'        => 'string',
					'description' => __( 'Checkup location or facility (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'notes'      => array(
					'type'        => 'string',
					'description' => __( 'Additional notes or instructions (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'status'     => array(
					'type'        => 'string',
					'description' => __( 'Checkup status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'scheduled', 'completed', 'cancelled', 'no-show' ),
				),
			),
			'required'             => array( 'checkup_id' ),
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

		if ( ! $current_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to update checkups.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get checkup ID.
		$checkup_id = isset( $arguments['checkup_id'] ) ? absint( $arguments['checkup_id'] ) : 0;

		if ( ! $checkup_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_checkup_id', __( 'Checkup ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify checkup exists.
		$checkup = get_post( $checkup_id );

		if ( ! $checkup || 'mcp_ai_checkup' !== $checkup->post_type ) {
			return new WP_Error( 'wp_mcp_ai_checkup_not_found', __( 'Checkup not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$is_author = absint( $checkup->post_author ) === $current_user_id;
		$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

		if ( ! $is_author && ! $can_edit_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this checkup.', 'mcp-ai-wpoos-pro' ) );
		}

		// Track updated fields.
		$updated_fields = array();

		// Update title if provided.
		if ( isset( $arguments['title'] ) ) {
			$title = sanitize_text_field( $arguments['title'] );
			if ( '' === $title ) {
				return new WP_Error( 'wp_mcp_ai_invalid_title', __( 'Checkup title cannot be empty.', 'mcp-ai-wpoos-pro' ) );
			}

			$result = wp_update_post( array(
				'ID'         => $checkup_id,
				'post_title' => $title,
			), true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_fields[] = 'title';
		}

		// Update notes if provided.
		if ( isset( $arguments['notes'] ) ) {
			$notes = sanitize_textarea_field( $arguments['notes'] );
			$result = wp_update_post( array(
				'ID'           => $checkup_id,
				'post_content' => $notes,
			), true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_fields[] = 'notes';
		}

		// Update date if provided.
		if ( isset( $arguments['date'] ) ) {
			$date = sanitize_text_field( $arguments['date'] );
			if ( ! empty( $date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $checkup_id, '_checkup_date', $date );
			$updated_fields[] = 'date';
		}

		// Update time if provided.
		if ( isset( $arguments['time'] ) ) {
			$time = sanitize_text_field( $arguments['time'] );
			if ( ! empty( $time ) && ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_time', __( 'Time must be in HH:MM format.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $checkup_id, '_checkup_time', $time );
			$updated_fields[] = 'time';
		}

		// Update provider if provided.
		if ( isset( $arguments['provider'] ) ) {
			$provider = sanitize_text_field( $arguments['provider'] );
			update_post_meta( $checkup_id, '_checkup_provider', $provider );
			$updated_fields[] = 'provider';
		}

		// Update location if provided.
		if ( isset( $arguments['location'] ) ) {
			$location = sanitize_text_field( $arguments['location'] );
			update_post_meta( $checkup_id, '_checkup_location', $location );
			$updated_fields[] = 'location';
		}

		// Update status if provided.
		if ( isset( $arguments['status'] ) ) {
			$status = sanitize_key( $arguments['status'] );
			update_post_meta( $checkup_id, '_checkup_status', $status );
			$updated_fields[] = 'status';
		}

		if ( empty( $updated_fields ) ) {
			return new WP_Error( 'wp_mcp_ai_no_updates', __( 'No fields were provided to update.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get updated checkup data.
		$updated_checkup = get_post( $checkup_id );

		$checkup_data = array(
			'id'          => $checkup_id,
			'title'       => $updated_checkup->post_title,
			'date'        => get_post_meta( $checkup_id, '_checkup_date', true ),
			'time'        => get_post_meta( $checkup_id, '_checkup_time', true ),
			'provider'    => get_post_meta( $checkup_id, '_checkup_provider', true ),
			'location'    => get_post_meta( $checkup_id, '_checkup_location', true ),
			'status'      => get_post_meta( $checkup_id, '_checkup_status', true ),
			'notes'       => $updated_checkup->post_content,
			'modified_at' => $updated_checkup->post_modified,
		);

		return array(
			'success'        => true,
			'checkup'        => $checkup_data,
			'updated_fields' => $updated_fields,
		);
	}
}
