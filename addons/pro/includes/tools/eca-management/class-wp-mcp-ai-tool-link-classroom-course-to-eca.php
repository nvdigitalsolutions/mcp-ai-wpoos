<?php
/**
 * Tool for linking a Google Classroom course to an ECA.
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
 * Links one Google Classroom course to one ECA record.
 */
class WP_MCP_AI_Tool_Link_Classroom_Course_To_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'link_classroom_course_to_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Link Classroom Course to ECA', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Links a Google Classroom course to an ECA record and sets the sync direction, so roster sync and announcements know which course belongs to which activity.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Pointing an existing ECA at its Classroom course before syncing rosters or posting announcements.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Bulk mapping; use sync_classroom_courses_to_ecas. Creating a course; use create_classroom_course.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'sync_classroom_courses_to_ecas', 'sync_classroom_roster_to_students', 'get_eca' ),
			'notes'           => __( 'Idempotent: re-linking simply updates the stored course ID and direction.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'  => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for Google Classroom.', 'mcp-ai-wpoos-pro' ),
				),
				'eca_id'         => array(
					'type'        => 'integer',
					'description' => __( 'WordPress ECA post ID to link.', 'mcp-ai-wpoos-pro' ),
				),
				'course_id'      => array(
					'type'        => 'string',
					'description' => __( 'Google Classroom course ID to link.', 'mcp-ai-wpoos-pro' ),
				),
				'sync_direction' => array(
					'type'        => 'string',
					'description' => __( 'Sync direction for this link.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'off', 'roster-in', 'bi-directional' ),
					'default'     => 'roster-in',
				),
			),
			'required'             => array( 'connection_id', 'eca_id', 'course_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
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
		return WP_MCP_AI_ECA_Classroom_Helper::feature_enabled();
	}

	/**
	 * Get unavailable reason message.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return WP_MCP_AI_ECA_Classroom_Helper::unavailable_reason();
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
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$resolved      = WP_MCP_AI_ECA_Classroom_Helper::get_client( $connection_id, 'manage_options', $context );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$scope_check = WP_MCP_AI_Google_Classroom_Credentials::require_scope(
			$resolved['credentials'],
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES_READONLY
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$eca_id    = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;
		$course_id = isset( $arguments['course_id'] ) ? sanitize_text_field( $arguments['course_id'] ) : '';
		$direction = isset( $arguments['sync_direction'] ) ? sanitize_key( $arguments['sync_direction'] ) : 'roster-in';

		if ( ! in_array( $direction, array( 'off', 'roster-in', 'bi-directional' ), true ) ) {
			$direction = 'roster-in';
		}

		$eca = $eca_id > 0 ? get_post( $eca_id ) : null;

		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error( 'wp_mcp_ai_eca_not_found', __( 'ECA record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $course_id ) {
			return new WP_Error( 'wp_mcp_ai_course_required', __( 'A Google Classroom course ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Confirm the course is visible to the connection before linking, so a
		// typo cannot silently point sync at a nonexistent course.
		$course = $resolved['client']->get_course( $course_id );

		if ( is_wp_error( $course ) ) {
			return $course;
		}

		update_post_meta( $eca_id, '_eca_google_course_id', $course_id );
		update_post_meta( $eca_id, '_eca_classroom_sync', $direction );

		return array(
			'success'        => true,
			'eca_id'         => $eca_id,
			'course_id'      => $course_id,
			'course_name'    => isset( $course['name'] ) ? sanitize_text_field( $course['name'] ) : '',
			'sync_direction' => $direction,
			'message'        => __( 'ECA linked to the Google Classroom course.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
