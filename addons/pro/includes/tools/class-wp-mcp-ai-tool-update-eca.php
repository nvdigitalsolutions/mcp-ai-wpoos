<?php
/**
 * Tool for updating ECAs (Extra-Curricular Activities).
 *
 * Allows AI assistants to update existing ECAs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing ECA.
 */
class WP_MCP_AI_Tool_Update_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update ECA', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing Extra-Curricular Activity (ECA). Only provided fields will be updated.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'            => array(
					'type'        => 'integer',
					'description' => __( 'ECA ID to update (required)', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'ECA name (optional)', 'wp-mcp-ai' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'ECA description (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 10000,
				),
				'eca_code'          => array(
					'type'        => 'string',
					'description' => __( 'ECA code/identifier (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 50,
				),
				'eca_type'          => array(
					'type'        => 'string',
					'description' => __( 'Type of ECA (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'club', 'society', 'sport_squad', 'sport_academy', 'other' ),
				),
				'day'               => array(
					'type'        => 'string',
					'description' => __( 'Day of the week (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
				),
				'time_start'        => array(
					'type'        => 'string',
					'description' => __( 'Start time in 24-hour format (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^([01]?[0-9]|2[0-3]):[0-5][0-9]$',
				),
				'time_end'          => array(
					'type'        => 'string',
					'description' => __( 'End time in 24-hour format (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^([01]?[0-9]|2[0-3]):[0-5][0-9]$',
				),
				'venue'             => array(
					'type'        => 'string',
					'description' => __( 'Venue/location (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 200,
				),
				'year_groups'       => array(
					'type'        => 'array',
					'description' => __( 'Array of year groups (optional)', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'teachers'          => array(
					'type'        => 'array',
					'description' => __( 'Array of teacher names (optional)', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'max_capacity'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of students (optional)', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 500,
				),
				'is_paid'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this is a paid activity (optional)', 'wp-mcp-ai' ),
				),
				'cost'              => array(
					'type'        => 'string',
					'description' => __( 'Cost details if paid activity (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 200,
				),
				'booking_type'      => array(
					'type'        => 'string',
					'description' => __( 'Booking method (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'preference', 'first_come_first_served', 'audition', 'pre_selected' ),
				),
				'isams_id'          => array(
					'type'        => 'string',
					'description' => __( 'iSAMS identifier (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 100,
				),
				'socs_id'           => array(
					'type'        => 'string',
					'description' => __( 'SOCS system identifier (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 100,
				),
			),
			'required'             => array( 'eca_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'database-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// ECA management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update ECAs.', 'wp-mcp-ai' ) );
		}

		$eca_id = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;

		if ( ! $eca_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'ECA ID is required.', 'wp-mcp-ai' ) );
		}

		// Verify the post exists and is an ECA.
		$post = get_post( $eca_id );
		if ( ! $post || 'mcp_ai_eca' !== $post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'ECA not found.', 'wp-mcp-ai' ) );
		}

		// Check if user can edit this post.
		if ( ! current_user_can( 'edit_post', $eca_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit this ECA.', 'wp-mcp-ai' ) );
		}

		// Update post fields if provided.
		$post_data = array( 'ID' => $eca_id );

		if ( isset( $arguments['name'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['name'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['description'] );
		}

		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update metadata if provided.
		if ( isset( $arguments['eca_code'] ) ) {
			update_post_meta( $eca_id, '_eca_code', sanitize_text_field( $arguments['eca_code'] ) );
		}

		if ( isset( $arguments['eca_type'] ) ) {
			$eca_type     = sanitize_key( $arguments['eca_type'] );
			$valid_types = array( 'club', 'society', 'sport_squad', 'sport_academy', 'other' );
			if ( in_array( $eca_type, $valid_types, true ) ) {
				update_post_meta( $eca_id, '_eca_type', $eca_type );
			}
		}

		if ( isset( $arguments['day'] ) ) {
			update_post_meta( $eca_id, '_eca_day', sanitize_text_field( $arguments['day'] ) );
		}

		if ( isset( $arguments['time_start'] ) ) {
			$time_start = sanitize_text_field( $arguments['time_start'] );
			if ( $this->validate_time( $time_start ) ) {
				update_post_meta( $eca_id, '_eca_time_start', $time_start );
			} else {
				return new WP_Error( 'wp_mcp_ai_invalid_time', __( 'Invalid start time format. Use HH:MM (24-hour format).', 'wp-mcp-ai' ) );
			}
		}

		if ( isset( $arguments['time_end'] ) ) {
			$time_end = sanitize_text_field( $arguments['time_end'] );
			if ( $this->validate_time( $time_end ) ) {
				update_post_meta( $eca_id, '_eca_time_end', $time_end );
			} else {
				return new WP_Error( 'wp_mcp_ai_invalid_time', __( 'Invalid end time format. Use HH:MM (24-hour format).', 'wp-mcp-ai' ) );
			}
		}

		if ( isset( $arguments['venue'] ) ) {
			update_post_meta( $eca_id, '_eca_venue', sanitize_text_field( $arguments['venue'] ) );
		}

		if ( isset( $arguments['year_groups'] ) && is_array( $arguments['year_groups'] ) ) {
			$year_groups = array_map( 'sanitize_text_field', $arguments['year_groups'] );
			update_post_meta( $eca_id, '_eca_year_groups', $year_groups );
		}

		if ( isset( $arguments['teachers'] ) && is_array( $arguments['teachers'] ) ) {
			$teachers = array_map( 'sanitize_text_field', $arguments['teachers'] );
			update_post_meta( $eca_id, '_eca_teachers', $teachers );
		}

		if ( isset( $arguments['max_capacity'] ) ) {
			update_post_meta( $eca_id, '_eca_max_capacity', absint( $arguments['max_capacity'] ) );
		}

		if ( isset( $arguments['is_paid'] ) ) {
			update_post_meta( $eca_id, '_eca_is_paid', (bool) $arguments['is_paid'] );
		}

		if ( isset( $arguments['cost'] ) ) {
			update_post_meta( $eca_id, '_eca_cost', sanitize_text_field( $arguments['cost'] ) );
		}

		if ( isset( $arguments['booking_type'] ) ) {
			$booking_type        = sanitize_key( $arguments['booking_type'] );
			$valid_booking_types = array( 'preference', 'first_come_first_served', 'audition', 'pre_selected' );
			if ( in_array( $booking_type, $valid_booking_types, true ) ) {
				update_post_meta( $eca_id, '_eca_booking_type', $booking_type );
			}
		}

		if ( isset( $arguments['isams_id'] ) ) {
			update_post_meta( $eca_id, '_eca_isams_id', sanitize_text_field( $arguments['isams_id'] ) );
		}

		if ( isset( $arguments['socs_id'] ) ) {
			update_post_meta( $eca_id, '_eca_socs_id', sanitize_text_field( $arguments['socs_id'] ) );
		}

		// Get updated ECA data.
		$updated_post = get_post( $eca_id );

		return array(
			'success' => true,
			'message' => __( 'ECA updated successfully.', 'wp-mcp-ai' ),
			'eca_id'  => $eca_id,
			'eca'     => array(
				'id'           => $eca_id,
				'name'         => $updated_post->post_title,
				'description'  => $updated_post->post_content,
				'eca_code'     => get_post_meta( $eca_id, '_eca_code', true ) ?: '',
				'type'         => get_post_meta( $eca_id, '_eca_type', true ) ?: 'club',
				'day'          => get_post_meta( $eca_id, '_eca_day', true ) ?: '',
				'time_start'   => get_post_meta( $eca_id, '_eca_time_start', true ) ?: '',
				'time_end'     => get_post_meta( $eca_id, '_eca_time_end', true ) ?: '',
				'venue'        => get_post_meta( $eca_id, '_eca_venue', true ) ?: '',
				'year_groups'  => get_post_meta( $eca_id, '_eca_year_groups', true ) ?: array(),
				'teachers'     => get_post_meta( $eca_id, '_eca_teachers', true ) ?: array(),
				'max_capacity' => absint( get_post_meta( $eca_id, '_eca_max_capacity', true ) ),
				'is_paid'      => (bool) get_post_meta( $eca_id, '_eca_is_paid', true ),
				'cost'         => get_post_meta( $eca_id, '_eca_cost', true ) ?: '',
				'booking_type' => get_post_meta( $eca_id, '_eca_booking_type', true ) ?: 'preference',
				'isams_id'     => get_post_meta( $eca_id, '_eca_isams_id', true ) ?: '',
				'socs_id'      => get_post_meta( $eca_id, '_eca_socs_id', true ) ?: '',
				'updated_at'   => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Validate time format (HH:MM).
	 *
	 * @param string $time Time string.
	 * @return bool
	 */
	private function validate_time( $time ) {
		return (bool) preg_match( '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time );
	}
}
