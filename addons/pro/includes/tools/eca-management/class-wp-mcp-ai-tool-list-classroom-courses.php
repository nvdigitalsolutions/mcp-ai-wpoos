<?php
/**
 * Tool for listing Google Classroom courses.
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
 * Lists Google Classroom courses visible to the connected account.
 */
class WP_MCP_AI_Tool_List_Classroom_Courses implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_classroom_courses';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Google Classroom Courses', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists Google Classroom courses visible to the connected teacher or coordinator account, with course state filtering and pagination.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Discovering the Classroom courses available to a connection, or finding the course ID needed to link, sync, or post to a course.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Importing courses into WordPress; use sync_classroom_courses_to_ecas. Creating or editing a course; use create_classroom_course or update_classroom_course.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'sync_classroom_courses_to_ecas', 'link_classroom_course_to_eca', 'sync_classroom_roster_to_students' ),
			'notes'           => __( 'Read-only. Returns raw Classroom course IDs and names; nothing is written to WordPress.', 'mcp-ai-wpoos-pro' ),
		);
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
					'description' => __( 'Remote Sites connection ID for Google Classroom.', 'mcp-ai-wpoos-pro' ),
				),
				'course_states' => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'ACTIVE', 'ARCHIVED', 'PROVISIONED', 'DECLINED', 'SUSPENDED' ),
					),
					'description' => __( 'Course states to include. Defaults to ACTIVE and ARCHIVED when omitted.', 'mcp-ai-wpoos-pro' ),
				),
				'page_size'     => array(
					'type'        => 'integer',
					'description' => __( 'Courses per page (default: 20, max: 100).', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'required'             => array( 'connection_id' ),
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'external-api' );
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
		$resolved      = WP_MCP_AI_ECA_Classroom_Helper::get_client( $connection_id, 'edit_posts', $context );

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

		$states = isset( $arguments['course_states'] ) && is_array( $arguments['course_states'] )
			? array_map( 'sanitize_key', $arguments['course_states'] )
			: array( 'ACTIVE', 'ARCHIVED' );

		$states    = array_values( array_filter( $states ) );
		$page_size = isset( $arguments['page_size'] ) ? min( 100, max( 1, absint( $arguments['page_size'] ) ) ) : 20;

		$params = array(
			'pageSize' => $page_size,
		);

		if ( ! empty( $states ) ) {
			$params['courseStates'] = $states;
		}

		$response = $resolved['client']->list_courses( $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$courses = isset( $response['courses'] ) && is_array( $response['courses'] ) ? $response['courses'] : array();

		$rows = array();
		foreach ( $courses as $course ) {
			if ( ! is_array( $course ) ) {
				continue;
			}

			$course_id = isset( $course['id'] ) ? sanitize_text_field( $course['id'] ) : '';
			$linked    = '' !== $course_id ? WP_MCP_AI_ECA_Classroom_Helper::find_eca_by_google_course( $course_id ) : 0;

			$rows[] = array(
				'course_id'    => $course_id,
				'name'         => isset( $course['name'] ) ? sanitize_text_field( $course['name'] ) : '',
				'section'      => isset( $course['section'] ) ? sanitize_text_field( $course['section'] ) : '',
				'course_state' => isset( $course['courseState'] ) ? sanitize_key( $course['courseState'] ) : '',
				'owner_id'     => isset( $course['ownerId'] ) ? sanitize_text_field( $course['ownerId'] ) : '',
				'linked_eca'   => $linked,
			);
		}

		return array(
			'success'         => true,
			'connection_id'   => $connection_id,
			'courses'         => $rows,
			'count'           => count( $rows ),
			'next_page_token' => isset( $response['nextPageToken'] ) ? sanitize_text_field( $response['nextPageToken'] ) : '',
			'message'         => sprintf(
				/* translators: %d: number of courses */
				__( '%d Google Classroom courses returned.', 'mcp-ai-wpoos-pro' ),
				count( $rows )
			),
		);
	}
}
