<?php
/**
 * Tool for syncing students from iSAMS.
 *
 * Allows AI assistants to sync student data from iSAMS School Management System.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs students from iSAMS into WordPress.
 */
class WP_MCP_AI_Tool_Sync_Students_From_ISAMS implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'sync_students_from_isams';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync Students from iSAMS', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Syncs student data from iSAMS School Management System into WordPress. Can sync individual students by ID or bulk sync by year group or all students.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites connection ID for iSAMS. If not provided, will use settings-based configuration.', 'mcp-ai-wpoos-pro' ),
				),
				'sync_type'   => array(
					'type'        => 'string',
					'description' => __( 'Type of sync to perform', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'single', 'year_group', 'all' ),
					'default'     => 'all',
				),
				'student_id'  => array(
					'type'        => 'string',
					'description' => __( 'iSAMS student ID (required when sync_type is "single")', 'mcp-ai-wpoos-pro' ),
				),
				'year_group'  => array(
					'type'        => 'string',
					'description' => __( 'Year group to sync (required when sync_type is "year_group")', 'mcp-ai-wpoos-pro' ),
				),
				'page'        => array(
					'type'        => 'integer',
					'description' => __( 'Page number for bulk sync (optional, default: 1)', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of students to sync per page (optional, default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'update_existing' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to update existing students (default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'sync_type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'external-api', 'database-write' );
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

		// Check if iSAMS is configured.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['isams_api_url'] ) || empty( $settings['isams_api_key'] ) ) {
			return false;
		}

		// Check if ECA management is enabled.
		return ! empty( $settings['enable_eca_management'] );
	}

	/**
	 * Get unavailable reason message.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['isams_api_url'] ) || empty( $settings['isams_api_key'] ) ) {
			return __( 'iSAMS API credentials are not configured.', 'mcp-ai-wpoos-pro' );
		}

		if ( empty( $settings['enable_eca_management'] ) ) {
			return __( 'ECA Management must be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Student sync tool is only available in the Pro version.', 'mcp-ai-wpoos-pro' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to sync students from iSAMS.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get iSAMS tool instance.
		if ( ! class_exists( 'WP_MCP_AI_Tool_ISAMS_Query' ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_unavailable',
				__( 'iSAMS integration tool is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get connection_id if provided and pass it along.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;

		// Validate connection if provided.
		if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_not_found',
					__( 'Connection not found. Please check the connection ID.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Validate connection type.
			if ( empty( $connection['connection_type'] ) || 'isams' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_pro_wrong_connection_type',
					__( 'This connection is not an iSAMS connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Check if connection is enabled.
			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_disabled',
					__( 'This connection is disabled. Please enable it in Remote Sites settings.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		$isams_tool = new WP_MCP_AI_Tool_ISAMS_Query();

		// Validate sync type.
		$sync_type = isset( $arguments['sync_type'] ) ? sanitize_key( $arguments['sync_type'] ) : 'all';
		$valid_sync_types = array( 'single', 'year_group', 'all' );
		if ( ! in_array( $sync_type, $valid_sync_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_sync_type',
				__( 'Invalid sync type.', 'mcp-ai-wpoos-pro' )
			);
		}

		$update_existing = isset( $arguments['update_existing'] ) ? (bool) $arguments['update_existing'] : true;
		$page = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit = min( $limit, 100 ); // Cap at 100.

		// Handle different sync types.
		switch ( $sync_type ) {
			case 'single':
				return $this->sync_single_student( $isams_tool, $arguments, $context, $update_existing );

			case 'year_group':
				return $this->sync_year_group( $isams_tool, $arguments, $context, $page, $limit, $update_existing );

			case 'all':
				return $this->sync_all_students( $isams_tool, $arguments, $context, $page, $limit, $update_existing );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_sync_type',
					__( 'Invalid sync type.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Sync a single student by ID.
	 *
	 * @param WP_MCP_AI_Tool_ISAMS_Query $isams_tool      iSAMS tool instance.
	 * @param array                       $arguments       Tool arguments.
	 * @param array                       $context         Execution context.
	 * @param bool                        $update_existing Whether to update existing students.
	 * @return array|WP_Error Sync results or error.
	 */
	private function sync_single_student( $isams_tool, $arguments, $context, $update_existing ) {
		$student_id = isset( $arguments['student_id'] ) ? sanitize_text_field( $arguments['student_id'] ) : '';

		if ( empty( $student_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_student_id',
				__( 'Student ID is required for single sync.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare query arguments, including connection_id if provided.
		$query_args = array(
			'endpoint' => 'pupils',
			'id'       => $student_id,
		);

		// Pass connection_id if provided.
		if ( isset( $arguments['connection_id'] ) ) {
			$query_args['connection_id'] = $arguments['connection_id'];
		}

		// Fetch student from iSAMS.
		$isams_result = $isams_tool->execute( $query_args, $context );

		if ( is_wp_error( $isams_result ) ) {
			return $isams_result;
		}

		if ( empty( $isams_result['data'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_student_not_found',
				__( 'Student not found in iSAMS.', 'mcp-ai-wpoos-pro' )
			);
		}

		$student_data = $isams_result['data'];
		$result = $this->create_or_update_student( $student_data, $update_existing );

		return array(
			'success'        => true,
			'sync_type'      => 'single',
			'students_synced' => 1,
			'student'        => $result,
			'message'        => __( 'Student synced successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Sync students by year group.
	 *
	 * @param WP_MCP_AI_Tool_ISAMS_Query $isams_tool      iSAMS tool instance.
	 * @param array                       $arguments       Tool arguments.
	 * @param array                       $context         Execution context.
	 * @param int                         $page            Page number.
	 * @param int                         $limit           Students per page.
	 * @param bool                        $update_existing Whether to update existing students.
	 * @return array|WP_Error Sync results or error.
	 */
	private function sync_year_group( $isams_tool, $arguments, $context, $page, $limit, $update_existing ) {
		$year_group = isset( $arguments['year_group'] ) ? sanitize_text_field( $arguments['year_group'] ) : '';

		if ( empty( $year_group ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_year_group',
				__( 'Year group is required for year group sync.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare query arguments, including connection_id if provided.
		$query_args = array(
			'endpoint' => 'pupils',
			'page'     => $page,
			'limit'    => $limit,
		);

		// Pass connection_id if provided.
		if ( isset( $arguments['connection_id'] ) ) {
			$query_args['connection_id'] = $arguments['connection_id'];
		}

		// Fetch students from iSAMS (paginated).
		$isams_result = $isams_tool->execute( $query_args, $context );

		if ( is_wp_error( $isams_result ) ) {
			return $isams_result;
		}

		$students = isset( $isams_result['data'] ) && is_array( $isams_result['data'] )
			? $isams_result['data']
			: array();

		// Filter by year group.
		$students = array_filter(
			$students,
			function( $student ) use ( $year_group ) {
				return isset( $student['yearGroup'] ) && $student['yearGroup'] === $year_group;
			}
		);

		return $this->process_students_batch( $students, $update_existing, 'year_group', $page, $limit );
	}

	/**
	 * Sync all students.
	 *
	 * @param WP_MCP_AI_Tool_ISAMS_Query $isams_tool      iSAMS tool instance.
	 * @param array                       $arguments       Tool arguments.
	 * @param array                       $context         Execution context.
	 * @param int                         $page            Page number.
	 * @param int                         $limit           Students per page.
	 * @param bool                        $update_existing Whether to update existing students.
	 * @return array|WP_Error Sync results or error.
	 */
	private function sync_all_students( $isams_tool, $arguments, $context, $page, $limit, $update_existing ) {
		// Prepare query arguments, including connection_id if provided.
		$query_args = array(
			'endpoint' => 'pupils',
			'page'     => $page,
			'limit'    => $limit,
		);

		// Pass connection_id if provided.
		if ( isset( $arguments['connection_id'] ) ) {
			$query_args['connection_id'] = $arguments['connection_id'];
		}

		// Fetch students from iSAMS (paginated).
		$isams_result = $isams_tool->execute( $query_args, $context );

		if ( is_wp_error( $isams_result ) ) {
			return $isams_result;
		}

		$students = isset( $isams_result['data'] ) && is_array( $isams_result['data'] )
			? $isams_result['data']
			: array();

		return $this->process_students_batch( $students, $update_existing, 'all', $page, $limit );
	}

	/**
	 * Process a batch of students.
	 *
	 * @param array  $students        Array of student data from iSAMS.
	 * @param bool   $update_existing Whether to update existing students.
	 * @param string $sync_type       Type of sync.
	 * @param int    $page            Current page number.
	 * @param int    $limit           Students per page.
	 * @return array Sync results.
	 */
	private function process_students_batch( $students, $update_existing, $sync_type, $page, $limit ) {
		$synced_count = 0;
		$created_count = 0;
		$updated_count = 0;
		$skipped_count = 0;
		$errors = array();

		foreach ( $students as $student_data ) {
			$result = $this->create_or_update_student( $student_data, $update_existing );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'student_id' => isset( $student_data['id'] ) ? $student_data['id'] : 'unknown',
					'error'      => $result->get_error_message(),
				);
			} elseif ( $result['action'] === 'created' ) {
				$created_count++;
				$synced_count++;
			} elseif ( $result['action'] === 'updated' ) {
				$updated_count++;
				$synced_count++;
			} elseif ( $result['action'] === 'skipped' ) {
				$skipped_count++;
			}
		}

		return array(
			'success'         => true,
			'sync_type'       => $sync_type,
			'page'            => $page,
			'limit'           => $limit,
			'total_processed' => count( $students ),
			'students_synced' => $synced_count,
			'created'         => $created_count,
			'updated'         => $updated_count,
			'skipped'         => $skipped_count,
			'errors'          => $errors,
			'has_more'        => count( $students ) >= $limit,
			'message'         => sprintf(
				/* translators: 1: synced count, 2: total processed */
				__( 'Synced %1$d of %2$d students.', 'mcp-ai-wpoos-pro' ),
				$synced_count,
				count( $students )
			),
		);
	}

	/**
	 * Create or update a student from iSAMS data.
	 *
	 * @param array $student_data    Student data from iSAMS.
	 * @param bool  $update_existing Whether to update existing students.
	 * @return array|WP_Error Student info or error.
	 */
	private function create_or_update_student( $student_data, $update_existing ) {
		// Extract student information.
		$isams_id = isset( $student_data['id'] ) ? sanitize_text_field( $student_data['id'] ) : '';
		$first_name = isset( $student_data['forename'] ) ? sanitize_text_field( $student_data['forename'] ) : '';
		$last_name = isset( $student_data['surname'] ) ? sanitize_text_field( $student_data['surname'] ) : '';
		$year_group = isset( $student_data['yearGroup'] ) ? sanitize_text_field( $student_data['yearGroup'] ) : '';
		$house = isset( $student_data['house'] ) ? sanitize_text_field( $student_data['house'] ) : '';
		$email = isset( $student_data['emailAddress'] ) ? sanitize_email( $student_data['emailAddress'] ) : '';

		if ( empty( $isams_id ) || empty( $first_name ) || empty( $last_name ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_student_data',
				__( 'Invalid student data from iSAMS.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if student already exists.
		$existing_post_id = get_option( 'wp_mcp_ai_student_isams_mapping_' . $isams_id );

		if ( $existing_post_id && ! $update_existing ) {
			return array(
				'action'     => 'skipped',
				'student_id' => $existing_post_id,
				'isams_id'   => $isams_id,
				'message'    => __( 'Student already exists and update_existing is false.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$student_name = trim( $first_name . ' ' . $last_name );
		$action = 'created';

		if ( $existing_post_id && get_post( $existing_post_id ) ) {
			// Update existing student.
			$post_id = wp_update_post(
				array(
					'ID'           => $existing_post_id,
					'post_title'   => $student_name,
					'post_status'  => 'publish',
				),
				true
			);
			$action = 'updated';
		} else {
			// Create new student.
			$post_id = wp_insert_post(
				array(
					'post_title'  => $student_name,
					'post_type'   => 'mcp_ai_student',
					'post_status' => 'publish',
				),
				true
			);
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Update student meta.
		update_post_meta( $post_id, '_student_first_name', $first_name );
		update_post_meta( $post_id, '_student_last_name', $last_name );
		update_post_meta( $post_id, '_student_year_group', $year_group );
		update_post_meta( $post_id, '_student_house', $house );
		update_post_meta( $post_id, '_student_email', $email );
		update_post_meta( $post_id, '_student_isams_id', $isams_id );
		update_post_meta( $post_id, '_student_isams_synced', 'yes' );
		update_post_meta( $post_id, '_student_isams_last_sync', current_time( 'mysql' ) );

		// Store mapping.
		update_option( 'wp_mcp_ai_student_isams_mapping_' . $isams_id, $post_id );

		return array(
			'action'     => $action,
			'student_id' => $post_id,
			'isams_id'   => $isams_id,
			'name'       => $student_name,
			'year_group' => $year_group,
			'house'      => $house,
		);
	}
}
