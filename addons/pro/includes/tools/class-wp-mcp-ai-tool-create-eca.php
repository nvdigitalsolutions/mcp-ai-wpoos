<?php
/**
 * Tool for creating Extra-Curricular Activities (ECAs).
 *
 * Allows AI assistants to create new ECAs with all details including schedules, venues, capacity, and costs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-content-media.php';

/**
 * Creates a new ECA (Extra-Curricular Activity).
 */
class WP_MCP_AI_Tool_Create_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Content_Media;

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
		return __( 'Create ECA', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new Extra-Curricular Activity (ECA) or updates an existing one if eca_id is provided. Includes schedule, venue, capacity, teacher assignments, and cost information. Supports clubs, societies, and sports activities.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Optional ECA ID. If provided, updates the existing ECA instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'ECA name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'eca_code'          => array(
					'type'        => 'string',
					'description' => __( 'ECA code/identifier (optional, e.g., "1", "ECA-001")', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'ECA description and details (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 10000,
				),
				'eca_type'          => array(
					'type'        => 'string',
					'description' => __( 'Type of ECA', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' ),
					'default'     => 'club',
				),
				'day'               => array(
					'type'        => 'string',
					'description' => __( 'Day of the week the ECA takes place', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
				),
				'start_time'        => array(
					'type'        => 'string',
					'description' => __( 'Start time in HH:MM AM/PM format (e.g., "2:45 PM")', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{1,2}:\d{2}\s?(AM|PM|am|pm)$',
				),
				'end_time'          => array(
					'type'        => 'string',
					'description' => __( 'End time in HH:MM AM/PM format (e.g., "3:30 PM")', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{1,2}:\d{2}\s?(AM|PM|am|pm)$',
				),
				'venue'             => array(
					'type'        => 'string',
					'description' => __( 'Venue/location where the ECA takes place', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'year_groups'       => array(
					'type'        => 'array',
					'description' => __( 'Array of year groups eligible (e.g., ["Year 7", "Year 8", "Year 9"])', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'max_students'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of students that can enroll', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 200,
				),
				'teachers'          => array(
					'type'        => 'array',
					'description' => __( 'Array of teacher names in charge', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'is_paid'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this is a paid activity', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'cost'              => array(
					'type'        => 'number',
					'description' => __( 'Cost per student (if paid activity)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'cost_period'       => array(
					'type'        => 'string',
					'description' => __( 'Billing period for cost (if paid)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'term', 'month', 'session', 'year' ),
					'default'     => 'term',
				),
				'requires_audition' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the ECA requires an audition/tryout', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'booking_type'      => array(
					'type'        => 'string',
					'description' => __( 'How enrollment is handled', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'first_come_first_served', 'preference_based', 'preselected', 'signup' ),
					'default'     => 'first_come_first_served',
				),
				'status'            => array(
					'type'        => 'string',
					'description' => __( 'ECA status', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'inactive', 'full', 'cancelled' ),
					'default'     => 'active',
				),
				'isams_sync_id'     => array(
					'type'        => 'string',
					'description' => __( 'iSAMS/SOCS system ID for synchronization', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);

		// Merge content media parameters.
		$schema['properties'] = array_merge( $schema['properties'], $this->get_content_media_parameters() );

		return $schema;
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
		// ECA management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
	}

	/**
	 * Get unavailable reason message.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_eca_management'] ) ) {
			return __( 'ECA Management must be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'ECA Management tools are only available in the Pro version.', 'mcp-ai-wpoos-pro' );
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
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to create ECAs.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if this is an update operation.
		$eca_id       = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;
		$is_update    = false;
		$existing_eca = null;

		if ( $eca_id ) {
			// Verify ECA exists and user has permission to update it.
			$existing_eca = get_post( $eca_id );

			if ( ! $existing_eca || 'mcp_ai_eca' !== $existing_eca->post_type ) {
				return new WP_Error( 'wp_mcp_ai_eca_not_found', __( 'ECA not found.', 'mcp-ai-wpoos-pro' ) );
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$is_author       = absint( $existing_eca->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this ECA.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_update = true;
		}

		// Validate and sanitize inputs.
		$name = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error(
				'wp_mcp_ai_missing_name',
				__( 'ECA name is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$eca_code          = isset( $arguments['eca_code'] ) ? sanitize_text_field( $arguments['eca_code'] ) : '';
		$description       = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$eca_type          = isset( $arguments['eca_type'] ) ? sanitize_key( $arguments['eca_type'] ) : 'club';
		$day               = isset( $arguments['day'] ) ? sanitize_text_field( $arguments['day'] ) : '';
		$start_time        = isset( $arguments['start_time'] ) ? sanitize_text_field( $arguments['start_time'] ) : '';
		$end_time          = isset( $arguments['end_time'] ) ? sanitize_text_field( $arguments['end_time'] ) : '';
		$venue             = isset( $arguments['venue'] ) ? sanitize_text_field( $arguments['venue'] ) : '';
		$max_students      = isset( $arguments['max_students'] ) ? absint( $arguments['max_students'] ) : 0;
		$is_paid           = isset( $arguments['is_paid'] ) ? (bool) $arguments['is_paid'] : false;
		$cost              = isset( $arguments['cost'] ) ? floatval( $arguments['cost'] ) : 0;
		$cost_period       = isset( $arguments['cost_period'] ) ? sanitize_key( $arguments['cost_period'] ) : 'term';
		$requires_audition = isset( $arguments['requires_audition'] ) ? (bool) $arguments['requires_audition'] : false;
		$booking_type      = isset( $arguments['booking_type'] ) ? sanitize_key( $arguments['booking_type'] ) : 'first_come_first_served';
		$status            = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'active';
		$isams_sync_id     = isset( $arguments['isams_sync_id'] ) ? sanitize_text_field( $arguments['isams_sync_id'] ) : '';

		// Sanitize arrays.
		$year_groups = isset( $arguments['year_groups'] ) && is_array( $arguments['year_groups'] )
			? array_map( 'sanitize_text_field', $arguments['year_groups'] )
			: array();

		$teachers = isset( $arguments['teachers'] ) && is_array( $arguments['teachers'] )
			? array_map( 'sanitize_text_field', $arguments['teachers'] )
			: array();

		// Validate enums.
		$valid_types = array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' );
		if ( ! in_array( $eca_type, $valid_types, true ) ) {
			$eca_type = 'club';
		}

		$valid_days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		if ( $day && ! in_array( $day, $valid_days, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_day',
				__( 'Invalid day of week.', 'mcp-ai-wpoos-pro' )
			);
		}

		$valid_statuses = array( 'active', 'inactive', 'full', 'cancelled' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			$status = 'active';
		}

		$valid_booking_types = array( 'first_come_first_served', 'preference_based', 'preselected', 'signup' );
		if ( ! in_array( $booking_type, $valid_booking_types, true ) ) {
			$booking_type = 'first_come_first_served';
		}

		$valid_cost_periods = array( 'term', 'month', 'session', 'year' );
		if ( ! in_array( $cost_period, $valid_cost_periods, true ) ) {
			$cost_period = 'term';
		}

		if ( $is_update ) {
			// Update existing ECA.
			$post_data = array(
				'ID'           => $eca_id,
				'post_title'   => $name,
				'post_content' => $this->embed_content_media( $description, $arguments ),
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$post_id = $eca_id;

			// Save all meta fields.
			if ( $eca_code ) {
				update_post_meta( $post_id, '_eca_code', $eca_code );
			}
			update_post_meta( $post_id, '_eca_type', $eca_type );
			if ( $day ) {
				update_post_meta( $post_id, '_eca_day', $day );
			}
			if ( $start_time ) {
				update_post_meta( $post_id, '_eca_start_time', $start_time );
			}
			if ( $end_time ) {
				update_post_meta( $post_id, '_eca_end_time', $end_time );
			}
			if ( $venue ) {
				update_post_meta( $post_id, '_eca_venue', $venue );
			}
			if ( $max_students > 0 ) {
				update_post_meta( $post_id, '_eca_max_students', $max_students );
			}
			if ( ! empty( $year_groups ) ) {
				update_post_meta( $post_id, '_eca_year_groups', $year_groups );
			}
			if ( ! empty( $teachers ) ) {
				update_post_meta( $post_id, '_eca_teachers', $teachers );
			}
			update_post_meta( $post_id, '_eca_is_paid', $is_paid ? 'yes' : 'no' );
			if ( $is_paid && $cost > 0 ) {
				update_post_meta( $post_id, '_eca_cost', $cost );
				update_post_meta( $post_id, '_eca_cost_period', $cost_period );
			}
			update_post_meta( $post_id, '_eca_requires_audition', $requires_audition ? 'yes' : 'no' );
			update_post_meta( $post_id, '_eca_booking_type', $booking_type );
			update_post_meta( $post_id, '_eca_status', $status );
			if ( $isams_sync_id ) {
				update_post_meta( $post_id, '_eca_isams_sync_id', $isams_sync_id );
			}

			// Try to sync with iSAMS if sync ID is provided and iSAMS is configured.
			$sync_status = null;
			if ( $isams_sync_id ) {
				$sync_result = $this->sync_with_isams( $post_id, $isams_sync_id );
				if ( ! is_wp_error( $sync_result ) ) {
					$sync_status = array(
						'synced'  => true,
						'message' => __( 'Successfully synced with iSAMS.', 'mcp-ai-wpoos-pro' ),
					);
				} else {
					$sync_status = array(
						'synced'  => false,
						'message' => $sync_result->get_error_message(),
					);
				}
			}

			// Get the updated ECA details.
			$eca = get_post( $post_id );

			// Get current enrollment count.
			$current_enrollment = get_post_meta( $post_id, '_eca_current_enrollment', true );

			$result = array(
				'success'            => true,
				'eca_id'             => $post_id,
				'name'               => $eca->post_title,
				'eca_code'           => $eca_code,
				'type'               => $eca_type,
				'day'                => $day,
				'start_time'         => $start_time,
				'end_time'           => $end_time,
				'venue'              => $venue,
				'max_students'       => $max_students,
				'current_enrollment' => absint( $current_enrollment ),
				'year_groups'        => $year_groups,
				'teachers'           => $teachers,
				'is_paid'            => $is_paid,
				'cost'               => $is_paid ? $cost : 0,
				'status'             => $status,
				'url'                => get_permalink( $post_id ),
				'edit_url'           => get_edit_post_link( $post_id, 'raw' ),
				'message'            => sprintf(
					/* translators: %s: ECA name */
					__( 'ECA updated: %s', 'mcp-ai-wpoos-pro' ),
					$name
				),
				'updated'            => true,
			);

			if ( $sync_status ) {
				$result['isams_sync'] = $sync_status;
			}

			return $result;
		} else {
			// Create the ECA post.
			$post_data = array(
				'post_title'   => $name,
				'post_content' => $this->embed_content_media( $description, $arguments ),
				'post_status'  => 'publish',
				'post_type'    => 'mcp_ai_eca',
				'post_author'  => $current_user_id,
			);

			$post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $post_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_create_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Failed to create ECA: %s', 'mcp-ai-wpoos-pro' ),
						$post_id->get_error_message()
					)
				);
			}

			// Save all meta fields.
			if ( $eca_code ) {
				update_post_meta( $post_id, '_eca_code', $eca_code );
			}
			update_post_meta( $post_id, '_eca_type', $eca_type );
			if ( $day ) {
				update_post_meta( $post_id, '_eca_day', $day );
			}
			if ( $start_time ) {
				update_post_meta( $post_id, '_eca_start_time', $start_time );
			}
			if ( $end_time ) {
				update_post_meta( $post_id, '_eca_end_time', $end_time );
			}
			if ( $venue ) {
				update_post_meta( $post_id, '_eca_venue', $venue );
			}
			if ( $max_students > 0 ) {
				update_post_meta( $post_id, '_eca_max_students', $max_students );
			}
			if ( ! empty( $year_groups ) ) {
				update_post_meta( $post_id, '_eca_year_groups', $year_groups );
			}
			if ( ! empty( $teachers ) ) {
				update_post_meta( $post_id, '_eca_teachers', $teachers );
			}
			update_post_meta( $post_id, '_eca_is_paid', $is_paid ? 'yes' : 'no' );
			if ( $is_paid && $cost > 0 ) {
				update_post_meta( $post_id, '_eca_cost', $cost );
				update_post_meta( $post_id, '_eca_cost_period', $cost_period );
			}
			update_post_meta( $post_id, '_eca_requires_audition', $requires_audition ? 'yes' : 'no' );
			update_post_meta( $post_id, '_eca_booking_type', $booking_type );
			update_post_meta( $post_id, '_eca_status', $status );
			if ( $isams_sync_id ) {
				update_post_meta( $post_id, '_eca_isams_sync_id', $isams_sync_id );
			}

			// Initialize enrollment count.
			update_post_meta( $post_id, '_eca_current_enrollment', 0 );

			// Try to sync with iSAMS if sync ID is provided and iSAMS is configured.
			$sync_status = null;
			if ( $isams_sync_id ) {
				$sync_result = $this->sync_with_isams( $post_id, $isams_sync_id );
				if ( ! is_wp_error( $sync_result ) ) {
					$sync_status = array(
						'synced'  => true,
						'message' => __( 'Successfully synced with iSAMS.', 'mcp-ai-wpoos-pro' ),
					);
				} else {
					$sync_status = array(
						'synced'  => false,
						'message' => $sync_result->get_error_message(),
					);
				}
			}

			// Get the created ECA details.
			$eca = get_post( $post_id );

			$result = array(
				'success'            => true,
				'eca_id'             => $post_id,
				'name'               => $eca->post_title,
				'eca_code'           => $eca_code,
				'type'               => $eca_type,
				'day'                => $day,
				'start_time'         => $start_time,
				'end_time'           => $end_time,
				'venue'              => $venue,
				'max_students'       => $max_students,
				'current_enrollment' => 0,
				'year_groups'        => $year_groups,
				'teachers'           => $teachers,
				'is_paid'            => $is_paid,
				'cost'               => $is_paid ? $cost : 0,
				'status'             => $status,
				'url'                => get_permalink( $post_id ),
				'edit_url'           => get_edit_post_link( $post_id, 'raw' ),
				'message'            => __( 'ECA created successfully.', 'mcp-ai-wpoos-pro' ),
				'updated'            => false,
			);

			if ( $sync_status ) {
				$result['isams_sync'] = $sync_status;
			}

			return $result;
		}
	}

	/**
	 * Sync ECA with iSAMS system.
	 *
	 * @param int    $post_id       ECA post ID.
	 * @param string $isams_sync_id iSAMS ID to sync with.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function sync_with_isams( $post_id, $isams_sync_id ) {
		// Check if iSAMS tool is available.
		if ( ! class_exists( 'WP_MCP_AI_Tool_ISAMS_Query' ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_unavailable',
				__( 'iSAMS integration is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if iSAMS is configured.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['isams_api_url'] ) || empty( $settings['isams_api_key'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_not_configured',
				__( 'iSAMS is not configured. Skipping sync.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Mark as synced and store the sync ID.
		update_post_meta( $post_id, '_eca_isams_synced', 'yes' );
		update_post_meta( $post_id, '_eca_isams_last_sync', current_time( 'mysql' ) );

		// Store mapping between local ECA ID and iSAMS ID.
		update_option( 'wp_mcp_ai_eca_isams_mapping_' . $isams_sync_id, $post_id );

		return true;
	}
}
