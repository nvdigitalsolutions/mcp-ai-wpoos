<?php
/**
 * Tool for creating students.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new student.
 */
class WP_MCP_AI_Tool_Create_Student implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_student';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Student', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new student or updates an existing one if student_id is provided. Includes personal details, year group, and house information. Students can then be enrolled in Extra-Curricular Activities.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional student ID. If provided, updates the existing student instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'Student first name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 100,
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'Student last name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 100,
				),
				'year_group' => array(
					'type'        => 'string',
					'description' => __( 'Year group (e.g., "Year 7", "Year 8") (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'house'      => array(
					'type'        => 'string',
					'description' => __( 'House name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'email'      => array(
					'type'        => 'string',
					'description' => __( 'Student email address (optional)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
				),
				'isams_id'   => array(
					'type'        => 'string',
					'description' => __( 'iSAMS student ID (optional, for integration)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
			),
			'required'             => array( 'first_name', 'last_name' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create students.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if this is an update operation.
		$student_id = isset( $arguments['student_id'] ) ? absint( $arguments['student_id'] ) : 0;
		$is_update  = false;

		if ( $student_id ) {
			// Verify student exists and user has permission to update it.
			$existing_student = get_post( $student_id );

			if ( ! $existing_student || 'mcp_ai_student' !== $existing_student->post_type ) {
				return new WP_Error( 'wp_mcp_ai_student_not_found', __( 'Student not found.', 'mcp-ai-wpoos-pro' ) );
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$is_author = absint( $existing_student->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this student.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_update = true;
		}

		// Validate required fields.
		$first_name = isset( $arguments['first_name'] ) ? sanitize_text_field( $arguments['first_name'] ) : '';
		$last_name  = isset( $arguments['last_name'] ) ? sanitize_text_field( $arguments['last_name'] ) : '';

		if ( '' === $first_name || '' === $last_name ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'First name and last name are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize optional fields.
		$year_group = isset( $arguments['year_group'] ) ? sanitize_text_field( $arguments['year_group'] ) : '';
		$house      = isset( $arguments['house'] ) ? sanitize_text_field( $arguments['house'] ) : '';
		$email      = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';
		$isams_id   = isset( $arguments['isams_id'] ) ? sanitize_text_field( $arguments['isams_id'] ) : '';

		// Validate email if provided.
		if ( $email && ! is_email( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_email', __( 'Invalid email address.', 'mcp-ai-wpoos-pro' ) );
		}

		$full_name = $first_name . ' ' . $last_name;

		if ( $is_update ) {
			// Update existing student.
			$post_data = array(
				'ID'         => $student_id,
				'post_title' => $full_name,
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Update student metadata.
			update_post_meta( $student_id, '_student_first_name', $first_name );
			update_post_meta( $student_id, '_student_last_name', $last_name );

			if ( $year_group ) {
				update_post_meta( $student_id, '_student_year_group', $year_group );
			}

			if ( $house ) {
				update_post_meta( $student_id, '_student_house', $house );
			}

			if ( $email ) {
				update_post_meta( $student_id, '_student_email', $email );
			}

			if ( $isams_id ) {
				update_post_meta( $student_id, '_student_isams_id', $isams_id );
			}

			$student = get_post( $student_id );

			return array(
				'success'    => true,
				'message'    => __( 'Student updated successfully.', 'mcp-ai-wpoos-pro' ),
				'student_id' => $student_id,
				'student'    => array(
					'id'         => $student_id,
					'name'       => $full_name,
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'year_group' => $year_group,
					'house'      => $house,
					'email'      => $email,
					'isams_id'   => $isams_id,
					'updated_at' => $student->post_modified,
				),
				'updated'    => true,
			);
		} else {
			// Create student post.
			$post_data = array(
				'post_type'   => 'mcp_ai_student',
				'post_title'  => $full_name,
				'post_status' => 'publish',
				'post_author' => $current_user_id,
			);

			$student_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $student_id ) ) {
				return $student_id;
			}

			// Save student metadata.
			update_post_meta( $student_id, '_student_first_name', $first_name );
			update_post_meta( $student_id, '_student_last_name', $last_name );

			if ( $year_group ) {
				update_post_meta( $student_id, '_student_year_group', $year_group );
			}

			if ( $house ) {
				update_post_meta( $student_id, '_student_house', $house );
			}

			if ( $email ) {
				update_post_meta( $student_id, '_student_email', $email );
			}

			if ( $isams_id ) {
				update_post_meta( $student_id, '_student_isams_id', $isams_id );
			}

			// Initialize empty enrollments array.
			update_post_meta( $student_id, '_student_eca_enrollments', array() );

			$student = get_post( $student_id );

			return array(
				'success'    => true,
				'message'    => __( 'Student created successfully.', 'mcp-ai-wpoos-pro' ),
				'student_id' => $student_id,
				'student'    => array(
					'id'         => $student_id,
					'name'       => $full_name,
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'year_group' => $year_group,
					'house'      => $house,
					'email'      => $email,
					'isams_id'   => $isams_id,
					'created_at' => $student->post_date,
				),
				'updated'    => false,
			);
		}
	}
}
