<?php
/**
 * Tool for creating ECAs (Extra-Curricular Activities).
 *
 * Allows AI assistants to create new ECAs for schools with support for iSAMS integration.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new ECA.
 */
class WP_MCP_AI_Tool_Create_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create ECA', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new Extra-Curricular Activity (ECA) including clubs, societies, sports squads, and academies. Supports SOCS and iSAMS integration for school management.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'         => array(
					'type'        => 'string',
					'description' => __( 'ECA name (required)', 'wp-mcp-ai' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'ECA description (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 10000,
				),
				'eca_code'     => array(
					'type'        => 'string',
					'description' => __( 'ECA code/identifier (e.g., "1", "2A") (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 50,
				),
				'eca_type'     => array(
					'type'        => 'string',
					'description' => __( 'Type of ECA', 'wp-mcp-ai' ),
					'enum'        => array( 'club', 'society', 'sport_squad', 'sport_academy', 'other' ),
					'default'     => 'club',
				),
				'day'          => array(
					'type'        => 'string',
					'description' => __( 'Day of the week (e.g., "Monday", "Tuesday") (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
				),
				'time_start'   => array(
					'type'        => 'string',
					'description' => __( 'Start time in 24-hour format (e.g., "14:45") (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^([01]?[0-9]|2[0-3]):[0-5][0-9]$',
				),
				'time_end'     => array(
					'type'        => 'string',
					'description' => __( 'End time in 24-hour format (e.g., "16:00") (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^([01]?[0-9]|2[0-3]):[0-5][0-9]$',
				),
				'venue'        => array(
					'type'        => 'string',
					'description' => __( 'Venue/location (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 200,
				),
				'year_groups'  => array(
					'type'        => 'array',
					'description' => __( 'Array of year groups (e.g., ["Year 7", "Year 8"]) (optional)', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'teachers'     => array(
					'type'        => 'array',
					'description' => __( 'Array of teacher names (optional)', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'max_capacity' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of students (optional)', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 500,
				),
				'is_paid'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this is a paid activity (default: false)', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'cost'         => array(
					'type'        => 'string',
					'description' => __( 'Cost details if paid activity (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 200,
				),
				'booking_type' => array(
					'type'        => 'string',
					'description' => __( 'Booking method', 'wp-mcp-ai' ),
					'enum'        => array( 'preference', 'first_come_first_served', 'audition', 'pre_selected' ),
					'default'     => 'preference',
				),
				'isams_id'     => array(
					'type'        => 'string',
					'description' => __( 'iSAMS identifier for synchronization (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 100,
				),
				'socs_id'      => array(
					'type'        => 'string',
					'description' => __( 'SOCS system identifier (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 100,
				),
			),
			'required'             => array( 'name' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create ECAs.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate and sanitize inputs.
		$name         = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$description  = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$eca_code     = isset( $arguments['eca_code'] ) ? sanitize_text_field( $arguments['eca_code'] ) : '';
		$eca_type     = isset( $arguments['eca_type'] ) ? sanitize_key( $arguments['eca_type'] ) : 'club';
		$day          = isset( $arguments['day'] ) ? sanitize_text_field( $arguments['day'] ) : '';
		$time_start   = isset( $arguments['time_start'] ) ? sanitize_text_field( $arguments['time_start'] ) : '';
		$time_end     = isset( $arguments['time_end'] ) ? sanitize_text_field( $arguments['time_end'] ) : '';
		$venue        = isset( $arguments['venue'] ) ? sanitize_text_field( $arguments['venue'] ) : '';
		$year_groups  = isset( $arguments['year_groups'] ) && is_array( $arguments['year_groups'] ) ? array_map( 'sanitize_text_field', $arguments['year_groups'] ) : array();
		$teachers     = isset( $arguments['teachers'] ) && is_array( $arguments['teachers'] ) ? array_map( 'sanitize_text_field', $arguments['teachers'] ) : array();
		$max_capacity = isset( $arguments['max_capacity'] ) ? absint( $arguments['max_capacity'] ) : 0;
		$is_paid      = isset( $arguments['is_paid'] ) ? (bool) $arguments['is_paid'] : false;
		$cost         = isset( $arguments['cost'] ) ? sanitize_text_field( $arguments['cost'] ) : '';
		$booking_type = isset( $arguments['booking_type'] ) ? sanitize_key( $arguments['booking_type'] ) : 'preference';
		$isams_id     = isset( $arguments['isams_id'] ) ? sanitize_text_field( $arguments['isams_id'] ) : '';
		$socs_id      = isset( $arguments['socs_id'] ) ? sanitize_text_field( $arguments['socs_id'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'ECA name is required.', 'wp-mcp-ai' ) );
		}

		// Validate ECA type.
		$valid_types = array( 'club', 'society', 'sport_squad', 'sport_academy', 'other' );
		if ( ! in_array( $eca_type, $valid_types, true ) ) {
			$eca_type = 'club';
		}

		// Validate booking type.
		$valid_booking_types = array( 'preference', 'first_come_first_served', 'audition', 'pre_selected' );
		if ( ! in_array( $booking_type, $valid_booking_types, true ) ) {
			$booking_type = 'preference';
		}

		// Validate time format.
		if ( $time_start && ! $this->validate_time( $time_start ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_time', __( 'Invalid start time format. Use HH:MM (24-hour format).', 'wp-mcp-ai' ) );
		}

		if ( $time_end && ! $this->validate_time( $time_end ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_time', __( 'Invalid end time format. Use HH:MM (24-hour format).', 'wp-mcp-ai' ) );
		}

		// Create ECA post.
		$post_data = array(
			'post_type'    => 'mcp_ai_eca',
			'post_title'   => $name,
			'post_content' => $description,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$eca_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $eca_id ) ) {
			return $eca_id;
		}

		// Save ECA metadata.
		if ( $eca_code ) {
			update_post_meta( $eca_id, '_eca_code', $eca_code );
		}
		update_post_meta( $eca_id, '_eca_type', $eca_type );
		if ( $day ) {
			update_post_meta( $eca_id, '_eca_day', $day );
		}
		if ( $time_start ) {
			update_post_meta( $eca_id, '_eca_time_start', $time_start );
		}
		if ( $time_end ) {
			update_post_meta( $eca_id, '_eca_time_end', $time_end );
		}
		if ( $venue ) {
			update_post_meta( $eca_id, '_eca_venue', $venue );
		}
		if ( ! empty( $year_groups ) ) {
			update_post_meta( $eca_id, '_eca_year_groups', $year_groups );
		}
		if ( ! empty( $teachers ) ) {
			update_post_meta( $eca_id, '_eca_teachers', $teachers );
		}
		if ( $max_capacity > 0 ) {
			update_post_meta( $eca_id, '_eca_max_capacity', $max_capacity );
		}
		update_post_meta( $eca_id, '_eca_is_paid', $is_paid );
		if ( $cost ) {
			update_post_meta( $eca_id, '_eca_cost', $cost );
		}
		update_post_meta( $eca_id, '_eca_booking_type', $booking_type );
		if ( $isams_id ) {
			update_post_meta( $eca_id, '_eca_isams_id', $isams_id );
		}
		if ( $socs_id ) {
			update_post_meta( $eca_id, '_eca_socs_id', $socs_id );
		}

		return array(
			'success' => true,
			'message' => __( 'ECA created successfully.', 'wp-mcp-ai' ),
			'eca_id'  => $eca_id,
			'eca'     => array(
				'id'           => $eca_id,
				'name'         => $name,
				'description'  => $description,
				'eca_code'     => $eca_code,
				'type'         => $eca_type,
				'day'          => $day,
				'time_start'   => $time_start,
				'time_end'     => $time_end,
				'venue'        => $venue,
				'year_groups'  => $year_groups,
				'teachers'     => $teachers,
				'max_capacity' => $max_capacity,
				'is_paid'      => $is_paid,
				'cost'         => $cost,
				'booking_type' => $booking_type,
				'isams_id'     => $isams_id,
				'socs_id'      => $socs_id,
				'created_at'   => current_time( 'mysql' ),
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
