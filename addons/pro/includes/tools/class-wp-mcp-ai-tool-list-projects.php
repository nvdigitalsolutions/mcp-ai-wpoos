<?php
/**
 * Tool for listing projects.
 *
 * Allows AI assistants to list and filter projects.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists projects with filtering options.
 */
class WP_MCP_AI_Tool_List_Projects implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_projects';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Projects', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists projects with optional filtering by status, date range, or assigned user. Useful for project management and calendar views.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by project status (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' ),
				),
				'assigned_to' => array(
					'type'        => 'integer',
					'description' => __( 'Filter by assigned user ID (optional)', 'wp-mcp-ai' ),
				),
				'start_after' => array(
					'type'        => 'string',
					'description' => __( 'Filter projects starting after this date (YYYY-MM-DD) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_before'  => array(
					'type'        => 'string',
					'description' => __( 'Filter projects ending before this date (YYYY-MM-DD) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of projects to return (default: 20, max: 100)', 'wp-mcp-ai' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		return (bool) get_option( 'wp_mcp_ai_enable_project_management', false );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list projects.', 'wp-mcp-ai' ) );
		}

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_project',
			'post_status'    => 'publish',
			'posts_per_page' => isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 100 ) : 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Filter by status.
		if ( ! empty( $arguments['status'] ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => '_project_status',
					'value'   => sanitize_key( $arguments['status'] ),
					'compare' => '=',
				),
			);
		}

		// Filter by assigned user.
		if ( ! empty( $arguments['assigned_to'] ) ) {
			if ( ! isset( $query_args['meta_query'] ) ) {
				$query_args['meta_query'] = array();
			}
			$query_args['meta_query'][] = array(
				'key'     => '_project_assigned_to',
				'value'   => sprintf( ':"%d";', absint( $arguments['assigned_to'] ) ),
				'compare' => 'LIKE',
			);
		}

		// Filter by date range.
		if ( ! empty( $arguments['start_after'] ) || ! empty( $arguments['end_before'] ) ) {
			if ( ! isset( $query_args['meta_query'] ) ) {
				$query_args['meta_query'] = array();
			}

			if ( ! empty( $arguments['start_after'] ) ) {
				$query_args['meta_query'][] = array(
					'key'     => '_project_start_date',
					'value'   => sanitize_text_field( $arguments['start_after'] ),
					'compare' => '>=',
					'type'    => 'DATE',
				);
			}

			if ( ! empty( $arguments['end_before'] ) ) {
				$query_args['meta_query'][] = array(
					'key'     => '_project_end_date',
					'value'   => sanitize_text_field( $arguments['end_before'] ),
					'compare' => '<=',
					'type'    => 'DATE',
				);
			}
		}

		$query    = new WP_Query( $query_args );
		$projects = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$project_id = get_the_ID();

				$projects[] = array(
					'id'          => $project_id,
					'name'        => get_the_title(),
					'description' => get_the_content(),
					'status'      => get_post_meta( $project_id, '_project_status', true ) ?: 'planning',
					'start_date'  => get_post_meta( $project_id, '_project_start_date', true ) ?: '',
					'end_date'    => get_post_meta( $project_id, '_project_end_date', true ) ?: '',
					'assigned_to' => get_post_meta( $project_id, '_project_assigned_to', true ) ?: array(),
					'created_at'  => get_the_date( 'c' ),
					'updated_at'  => get_the_modified_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'  => true,
			'count'    => count( $projects ),
			'total'    => $query->found_posts,
			'projects' => $projects,
		);
	}
}
