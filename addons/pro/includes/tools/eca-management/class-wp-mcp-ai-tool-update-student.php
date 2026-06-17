<?php
/**
 * Tool for updating students.
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
 * Updates an existing student.
 */
class WP_MCP_AI_Tool_Update_Student implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_student';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Student', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing student. Provide only the fields you want to update.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Student ID to update (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'New first name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'New last name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'year_group' => array(
					'type'        => 'string',
					'description' => __( 'New year group (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'house'      => array(
					'type'        => 'string',
					'description' => __( 'New house (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'email'      => array(
					'type'        => 'string',
					'description' => __( 'New email address (optional)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
				),
				'isams_id'   => array(
					'type'        => 'string',
					'description' => __( 'New iSAMS ID (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
			),
			'required'             => array( 'student_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			'post_type'             => 'mcp_ai_student',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'educator', 'school_admin' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update students.', 'mcp-ai-wpoos-pro' ) );
		}

		$student_id = isset( $arguments['student_id'] ) ? absint( $arguments['student_id'] ) : 0;

		if ( ! $student_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Student ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$student = get_post( $student_id );

		if ( ! $student || 'mcp_ai_student' !== $student->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_student', __( 'Invalid student ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Update name if first or last name provided.
		if ( isset( $arguments['first_name'] ) || isset( $arguments['last_name'] ) ) {
			$first_name = isset( $arguments['first_name'] ) ? sanitize_text_field( $arguments['first_name'] ) : get_post_meta( $student_id, '_student_first_name', true );
			$last_name  = isset( $arguments['last_name'] ) ? sanitize_text_field( $arguments['last_name'] ) : get_post_meta( $student_id, '_student_last_name', true );

			$full_name = trim( $first_name . ' ' . $last_name );

			if ( $full_name ) {
				wp_update_post(
					array(
						'ID'         => $student_id,
						'post_title' => $full_name,
					)
				);
			}

			if ( isset( $arguments['first_name'] ) ) {
				update_post_meta( $student_id, '_student_first_name', $first_name );
			}

			if ( isset( $arguments['last_name'] ) ) {
				update_post_meta( $student_id, '_student_last_name', $last_name );
			}
		}

		// Update other metadata fields.
		$meta_fields = array(
			'year_group' => '_student_year_group',
			'house'      => '_student_house',
			'email'      => '_student_email',
			'isams_id'   => '_student_isams_id',
		);

		foreach ( $meta_fields as $arg_key => $meta_key ) {
			if ( isset( $arguments[ $arg_key ] ) ) {
				$value = $arguments[ $arg_key ];

				// Validate email if provided.
				if ( 'email' === $arg_key && $value && ! is_email( $value ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_email', __( 'Invalid email address.', 'mcp-ai-wpoos-pro' ) );
				}

				if ( 'email' === $arg_key ) {
					$value = sanitize_email( $value );
				} else {
					$value = sanitize_text_field( $value );
				}

				update_post_meta( $student_id, $meta_key, $value );
			}
		}

		return array(
			'success'    => true,
			'message'    => __( 'Student updated successfully.', 'mcp-ai-wpoos-pro' ),
			'student_id' => $student_id,
		);
	}
}
