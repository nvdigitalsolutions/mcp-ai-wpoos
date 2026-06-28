<?php
/**
 * Tool for syncing ECAs from SOCS.
 *
 * Allows AI assistants to sync ECA data from SOCS (School Online Communication
 * System) into WordPress.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs Extra-Curricular Activity data from SOCS into WordPress.
 */
class WP_MCP_AI_Tool_Sync_ECAs_From_SOCS implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'sync_ecas_from_socs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync ECAs from SOCS', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Syncs Extra-Curricular Activity data from SOCS (School Online Communication System) into WordPress. Imports activities, schedules, and student assignments from SOCS API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'api_url'       => array(
					'type'        => 'string',
					'description' => __( 'SOCS API base URL. Overrides the value stored in plugin settings.', 'mcp-ai-wpoos-pro' ),
				),
				'api_key'       => array(
					'type'        => 'string',
					'description' => __( 'SOCS API key. Overrides the value stored in plugin settings.', 'mcp-ai-wpoos-pro' ),
				),
				'activity_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter activities by type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'all', 'clubs', 'sports', 'music', 'drama' ),
					'default'     => 'all',
				),
				'sync_students' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, also syncs enrolled student data for each activity.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'description' => __( 'When true, simulates the sync without writing any data.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
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
			'toolkit'               => 'education',
			'post_type'             => 'mcp_ai_eca',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'school_admin', 'it_admin' ),
			'risk_level'            => 'elevated',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'external-api' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// SOCS sync is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if ECA management is enabled.
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

		return __( 'SOCS sync tool is only available in the Pro version.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to sync ECAs from SOCS.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Resolve SOCS API credentials from arguments or settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$socs_url = isset( $arguments['api_url'] ) && ! empty( $arguments['api_url'] )
			? esc_url_raw( $arguments['api_url'] )
			: ( isset( $settings['socs_api_url'] ) ? esc_url_raw( $settings['socs_api_url'] ) : '' );
		$socs_key = isset( $arguments['api_key'] ) && ! empty( $arguments['api_key'] )
			? sanitize_text_field( $arguments['api_key'] )
			: ( isset( $settings['socs_api_key'] ) ? $settings['socs_api_key'] : '' );

		if ( empty( $socs_url ) || empty( $socs_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_socs_not_configured',
				__( 'SOCS API credentials are not configured. Provide api_url and api_key parameters or set socs_api_url and socs_api_key in plugin settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		$activity_type = isset( $arguments['activity_type'] ) ? sanitize_key( $arguments['activity_type'] ) : 'all';
		$sync_students = isset( $arguments['sync_students'] ) ? (bool) $arguments['sync_students'] : true;
		$dry_run       = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : false;

		// Validate activity_type.
		$valid_types = array( 'all', 'clubs', 'sports', 'music', 'drama' );
		if ( ! in_array( $activity_type, $valid_types, true ) ) {
			$activity_type = 'all';
		}

		// Build the SOCS API endpoint.
		$api_endpoint = trailingslashit( $socs_url ) . 'api/activities';

		if ( 'all' !== $activity_type ) {
			$api_endpoint = add_query_arg( 'type', rawurlencode( $activity_type ), $api_endpoint );
		}

		// Fetch activities from SOCS.
		$response = wp_remote_get(
			$api_endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $socs_key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_socs_request_failed',
				sprintf(
					/* translators: %s: Error message from the HTTP request */
					__( 'Failed to connect to SOCS API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_socs_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'SOCS API returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$status_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'wp_mcp_ai_socs_invalid_response',
				__( 'Invalid JSON response from SOCS API.', 'mcp-ai-wpoos-pro' )
			);
		}

		$activities = isset( $data['activities'] ) ? $data['activities'] : $data;
		if ( ! is_array( $activities ) ) {
			$activities = array();
		}

		// Process each activity.
		$created_count   = 0;
		$updated_count   = 0;
		$students_synced = 0;
		$errors          = array();

		foreach ( $activities as $activity ) {
			$result = $this->process_activity( $activity, $sync_students, $socs_url, $socs_key, $dry_run, $context );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'socs_id' => isset( $activity['id'] ) ? sanitize_text_field( $activity['id'] ) : 'unknown',
					'name'    => isset( $activity['name'] ) ? sanitize_text_field( $activity['name'] ) : 'Unknown',
					'error'   => $result->get_error_message(),
				);
				continue;
			}

			if ( 'created' === $result['action'] ) {
				++$created_count;
			} else {
				++$updated_count;
			}

			if ( isset( $result['students_synced'] ) ) {
				$students_synced += $result['students_synced'];
			}
		}

		$total_activities = $created_count + $updated_count;

		return array(
			'success'          => true,
			'total_activities' => $total_activities,
			'created_count'    => $created_count,
			'updated_count'    => $updated_count,
			'students_synced'  => $students_synced,
			'errors'           => $errors,
			'dry_run'          => $dry_run,
			'message'          => $dry_run
				? sprintf(
					/* translators: 1: Total activities, 2: created, 3: updated, 4: students synced */
					__( 'Dry run complete. %1$d activities would be synced (%2$d created, %3$d updated), %4$d students.', 'mcp-ai-wpoos-pro' ),
					$total_activities,
					$created_count,
					$updated_count,
					$students_synced
				)
				: sprintf(
					/* translators: 1: Total activities, 2: created, 3: updated, 4: students synced */
					__( '%1$d activities synced from SOCS (%2$d created, %3$d updated), %4$d students synced.', 'mcp-ai-wpoos-pro' ),
					$total_activities,
					$created_count,
					$updated_count,
					$students_synced
				),
		);
	}

	/**
	 * Process a single activity from SOCS.
	 *
	 * @param array  $activity      Activity data from SOCS.
	 * @param bool   $sync_students Whether to sync enrolled students.
	 * @param string $socs_url      SOCS API base URL.
	 * @param string $socs_key      SOCS API key.
	 * @param bool   $dry_run       Whether this is a dry run.
	 * @param array  $context       Execution context.
	 * @return array|WP_Error Processing result or error.
	 */
	private function process_activity( $activity, $sync_students, $socs_url, $socs_key, $dry_run, $context ) {
		$socs_id = isset( $activity['id'] ) ? sanitize_text_field( $activity['id'] ) : '';
		if ( empty( $socs_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_socs_id',
				__( 'Activity record is missing an ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Map SOCS fields to ECA fields.
		$eca_name    = isset( $activity['name'] ) ? sanitize_text_field( $activity['name'] ) : '';
		$description = isset( $activity['description'] ) ? wp_kses_post( $activity['description'] ) : '';
		$eca_type    = $this->map_socs_type( $activity );
		$day         = isset( $activity['day'] ) ? sanitize_text_field( $activity['day'] ) : '';
		$start_time  = isset( $activity['start_time'] ) ? sanitize_text_field( $activity['start_time'] ) : '';
		$end_time    = isset( $activity['end_time'] ) ? sanitize_text_field( $activity['end_time'] ) : '';
		$venue       = isset( $activity['venue'] ) ? sanitize_text_field( $activity['venue'] ) : '';
		$capacity    = isset( $activity['capacity'] ) ? absint( $activity['capacity'] ) : 0;

		if ( empty( $eca_name ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_activity_name',
				__( 'Activity name is missing in SOCS data.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if ECA already exists by SOCS ID.
		$existing_post_id = $this->find_eca_by_socs_id( $socs_id );

		if ( $dry_run ) {
			$student_count = 0;
			if ( $sync_students && ! empty( $activity['students'] ) && is_array( $activity['students'] ) ) {
				$student_count = count( $activity['students'] );
			}

			return array(
				'action'          => $existing_post_id ? 'would_update' : 'would_create',
				'eca_id'          => $existing_post_id ? $existing_post_id : null,
				'socs_id'         => $socs_id,
				'name'            => $eca_name,
				'students_synced' => $student_count,
			);
		}

		if ( $existing_post_id ) {
			// Update existing ECA.
			$post_data = array(
				'ID'           => $existing_post_id,
				'post_title'   => $eca_name,
				'post_content' => $description,
				'post_status'  => 'publish',
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$post_id = $existing_post_id;
			$action  = 'updated';
		} else {
			// Create new ECA.
			$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

			$post_data = array(
				'post_title'   => $eca_name,
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_type'    => 'mcp_ai_eca',
				'post_author'  => $current_user_id,
			);

			$post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$action = 'created';

			// Initialize enrollment count for new ECAs.
			update_post_meta( $post_id, '_eca_current_enrollment', 0 );
		}

		// Update meta fields.
		update_post_meta( $post_id, '_eca_type', $eca_type );
		update_post_meta( $post_id, '_eca_day', $day );
		update_post_meta( $post_id, '_eca_start_time', $start_time );
		update_post_meta( $post_id, '_eca_end_time', $end_time );
		update_post_meta( $post_id, '_eca_venue', $venue );
		update_post_meta( $post_id, '_eca_max_students', $capacity );
		update_post_meta( $post_id, '_eca_socs_id', $socs_id );
		update_post_meta( $post_id, '_eca_socs_last_sync', current_time( 'mysql' ) );

		// Sync enrolled students if enabled.
		$student_count = 0;
		if ( $sync_students ) {
			$student_count = $this->sync_activity_students( $post_id, $activity, $socs_url, $socs_key );
		}

		return array(
			'action'          => $action,
			'eca_id'          => $post_id,
			'socs_id'         => $socs_id,
			'name'            => $eca_name,
			'students_synced' => $student_count,
		);
	}

	/**
	 * Sync enrolled students for an activity.
	 *
	 * @param int    $eca_post_id WordPress ECA post ID.
	 * @param array  $activity    Activity data from SOCS (may include inline students).
	 * @param string $socs_url    SOCS API base URL.
	 * @param string $socs_key    SOCS API key.
	 * @return int Number of students synced.
	 */
	private function sync_activity_students( $eca_post_id, $activity, $socs_url, $socs_key ) {
		// Students may be included inline or fetched separately.
		$students = array();

		if ( ! empty( $activity['students'] ) && is_array( $activity['students'] ) ) {
			$students = $activity['students'];
		} else {
			// Fetch enrolled students from SOCS API.
			$socs_id      = isset( $activity['id'] ) ? sanitize_text_field( $activity['id'] ) : '';
			$api_endpoint = trailingslashit( $socs_url ) . 'api/activities/' . rawurlencode( $socs_id ) . '/students';

			$response = wp_remote_get(
				$api_endpoint,
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . $socs_key,
						'Accept'        => 'application/json',
					),
				)
			);

			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = wp_remote_retrieve_body( $response );
				$data = json_decode( $body, true );

				if ( is_array( $data ) ) {
					$students = isset( $data['students'] ) ? $data['students'] : $data;
				}
			}
		}

		if ( ! is_array( $students ) || empty( $students ) ) {
			return 0;
		}

		$synced = 0;

		foreach ( $students as $student_data ) {
			$socs_student_id = isset( $student_data['id'] ) ? sanitize_text_field( $student_data['id'] ) : '';
			if ( empty( $socs_student_id ) ) {
				continue;
			}

			// Match student by SOCS ID.
			$student_post_id = $this->find_student_by_socs_id( $socs_student_id );
			if ( ! $student_post_id ) {
				continue;
			}

			// Add enrollment record to ECA.
			$enrollments = get_post_meta( $eca_post_id, '_student_eca_enrollments', true );
			if ( ! is_array( $enrollments ) ) {
				$enrollments = array();
			}

			// Check if already enrolled.
			$already_enrolled = false;
			foreach ( $enrollments as $existing ) {
				if ( isset( $existing['student_id'] ) && absint( $existing['student_id'] ) === $student_post_id ) {
					$already_enrolled = true;
					break;
				}
			}

			if ( ! $already_enrolled ) {
				$enrollments[] = array(
					'student_id'      => $student_post_id,
					'socs_student_id' => $socs_student_id,
					'enrollment_type' => 'confirmed',
					'enrolled_date'   => current_time( 'mysql' ),
					'synced_from'     => 'socs',
					'synced_at'       => current_time( 'mysql' ),
				);
				update_post_meta( $eca_post_id, '_student_eca_enrollments', $enrollments );
				++$synced;
			}
		}

		return $synced;
	}

	/**
	 * Find ECA by SOCS ID.
	 *
	 * @param string $socs_id SOCS activity ID.
	 * @return int|null Post ID if found, null otherwise.
	 */
	private function find_eca_by_socs_id( $socs_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'any',
				'meta_query'     => array(
					array(
						'key'     => '_eca_socs_id',
						'value'   => $socs_id,
						'compare' => '=',
					),
				),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return $query->have_posts() ? $query->posts[0] : null;
	}

	/**
	 * Find student by SOCS ID.
	 *
	 * @param string $socs_student_id SOCS student ID.
	 * @return int|null Post ID if found, null otherwise.
	 */
	private function find_student_by_socs_id( $socs_student_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_student',
				'post_status'    => 'any',
				'meta_query'     => array(
					array(
						'key'     => '_student_socs_id',
						'value'   => $socs_student_id,
						'compare' => '=',
					),
				),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return $query->have_posts() ? $query->posts[0] : null;
	}

	/**
	 * Map SOCS activity type to plugin ECA type.
	 *
	 * @param array $activity Activity data from SOCS.
	 * @return string ECA type.
	 */
	private function map_socs_type( $activity ) {
		$type = isset( $activity['type'] ) ? strtolower( sanitize_key( $activity['type'] ) ) : '';

		$type_map = array(
			'club'     => 'club',
			'clubs'    => 'club',
			'sport'    => 'sport_squad',
			'sports'   => 'sport_squad',
			'music'    => 'activity',
			'drama'    => 'activity',
			'society'  => 'society',
			'activity' => 'activity',
		);

		return isset( $type_map[ $type ] ) ? $type_map[ $type ] : 'club';
	}
}
