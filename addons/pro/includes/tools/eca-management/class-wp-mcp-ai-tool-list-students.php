<?php
/**
 * Tool for listing students.
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
 * List students with pagination and filtering.
 */
class WP_MCP_AI_Tool_List_Students implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_students';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Students', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists all students with optional filtering by year group and house. Includes enrollment counts and basic details.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'year_group' => array(
					'type'        => 'string',
					'description' => __( 'Filter by year group (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'house'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by house (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'search'     => array(
					'type'        => 'string',
					'description' => __( 'Search by student name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'per_page'   => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'       => array(
					'type'        => 'integer',
					'description' => __( 'Page number (default: 1)', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
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
			'profession_tags'       => array( 'educator', 'school_admin', 'registrar' ),
			'risk_level'            => 'info',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list students.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize inputs.
		$year_group = isset( $arguments['year_group'] ) ? sanitize_text_field( $arguments['year_group'] ) : '';
		$house      = isset( $arguments['house'] ) ? sanitize_text_field( $arguments['house'] ) : '';
		$search     = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$per_page   = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page       = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		// Validate per_page.
		if ( $per_page < 1 || $per_page > 100 ) {
			$per_page = 20;
		}

		// Build query.
		$query_args = array(
			'post_type'      => 'mcp_ai_student',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Add search if provided.
		if ( $search ) {
			$query_args['s'] = $search;
		}

		// Build meta query for filters.
		$meta_query = array( 'relation' => 'AND' );

		if ( $year_group ) {
			$meta_query[] = array(
				'key'   => '_student_year_group',
				'value' => $year_group,
			);
		}

		if ( $house ) {
			$meta_query[] = array(
				'key'   => '_student_house',
				'value' => $house,
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $query_args );

		$students = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$student_id = get_the_ID();

				// Get enrollment count.
				$enrollments      = get_post_meta( $student_id, '_student_eca_enrollments', true );
				$enrollment_count = is_array( $enrollments ) ? count( $enrollments ) : 0;

				$students[] = array(
					'id'               => $student_id,
					'name'             => get_the_title(),
					'first_name'       => get_post_meta( $student_id, '_student_first_name', true ),
					'last_name'        => get_post_meta( $student_id, '_student_last_name', true ),
					'year_group'       => get_post_meta( $student_id, '_student_year_group', true ),
					'house'            => get_post_meta( $student_id, '_student_house', true ),
					'email'            => get_post_meta( $student_id, '_student_email', true ),
					'enrollment_count' => $enrollment_count,
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'    => true,
			'students'   => $students,
			'pagination' => array(
				'total'        => $query->found_posts,
				'total_pages'  => $query->max_num_pages,
				'current_page' => $page,
				'per_page'     => $per_page,
			),
		);
	}
}
