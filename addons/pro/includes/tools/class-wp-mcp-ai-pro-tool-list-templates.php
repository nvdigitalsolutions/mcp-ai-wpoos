<?php
/**
 * Tool: List Templates
 *
 * Lists available task plan templates with filtering and sorting options
 *
 * @package MCP_AI_WP_OOS_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List Templates Tool Class
 */
class WP_MCP_AI_Pro_Tool_List_Templates {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'list_templates';
	}

	/**
	 * Get tool definition for AI
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'         => 'list_templates',
			'description'  => 'Lists available task plan templates with filtering by category, status, and sorting options. Shows usage statistics and template metadata.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'category' => array(
						'type'        => 'string',
						'description' => 'Filter by category',
						'enum'        => array( 'all', 'research', 'content', 'data_analysis', 'development', 'marketing', 'custom' ),
					),
					'status'   => array(
						'type'        => 'string',
						'description' => 'Filter by status',
						'enum'        => array( 'all', 'draft', 'published', 'archived' ),
					),
					'sort_by'  => array(
						'type'        => 'string',
						'description' => 'Sort templates by field',
						'enum'        => array( 'name', 'usage_count', 'success_rate', 'created', 'modified' ),
					),
					'order'    => array(
						'type'        => 'string',
						'description' => 'Sort order',
						'enum'        => array( 'asc', 'desc' ),
					),
					'limit'    => array(
						'type'        => 'number',
						'description' => 'Maximum number of templates to return (default: 20)',
					),
				),
				'required'   => array(),
			),
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Extract arguments.
		$category = sanitize_text_field( $arguments['category'] ?? 'all' );
		$status   = sanitize_text_field( $arguments['status'] ?? 'all' );
		$sort_by  = sanitize_text_field( $arguments['sort_by'] ?? 'usage_count' );
		$order    = sanitize_text_field( $arguments['order'] ?? 'desc' );
		$limit    = absint( $arguments['limit'] ?? 20 );

		// Check if we should use CCT or CPT.
		$use_cct = $this->should_use_cct();

		$templates = array();

		if ( $use_cct ) {
			// Query templates using CCT.
			$handler = WP_MCP_AI_Task_Templates_CCT::get_item_handler();
			if ( ! $handler ) {
				return array(
					'success' => false,
					'error'   => 'CCT handler not available',
				);
			}

			$query_args = array(
				'per_page' => $limit,
				'orderby'  => $this->map_sort_field_cct( $sort_by ),
				'order'    => strtoupper( $order ),
			);

			// Apply filters.
			if ( 'all' !== $category ) {
				$query_args['category'] = $category;
			}
			if ( 'all' !== $status ) {
				$query_args['status'] = $status;
			}

			$factory       = jet_engine()->listings->data->get_listing_data( 'mcp_task_templates' );
			$raw_templates = $factory ? $factory->db->query( $query_args ) : array();

			foreach ( $raw_templates as $template ) {
				$templates[] = $this->format_template_cct( $template );
			}
		} else {
			// Query templates using CPT.
			$query_args = array(
				'post_type'      => 'mcp_task_template',
				'posts_per_page' => $limit,
				'orderby'        => $this->map_sort_field_cpt( $sort_by ),
				'order'          => strtoupper( $order ),
			);

			// Apply status filter.
			if ( 'all' !== $status ) {
				$status_map                = array(
					'draft'     => 'draft',
					'published' => 'publish',
					'archived'  => 'private',
				);
				$query_args['post_status'] = $status_map[ $status ] ?? 'publish';
			} else {
				$query_args['post_status'] = array( 'draft', 'publish', 'private' );
			}

			// Apply category filter.
			if ( 'all' !== $category ) {
				$query_args['meta_query'] = array(
					array(
						'key'     => 'category',
						'value'   => $category,
						'compare' => '=',
					),
				);
			}

			$query = new WP_Query( $query_args );

			foreach ( $query->posts as $post ) {
				$templates[] = $this->format_template_cpt( $post );
			}
		}

		// Count templates by category.
		$category_counts = array();
		foreach ( $templates as $template ) {
			$cat                     = $template['category'];
			$category_counts[ $cat ] = ( $category_counts[ $cat ] ?? 0 ) + 1;
		}

		return array(
			'success'         => true,
			'templates'       => $templates,
			'total_count'     => count( $templates ),
			'category_counts' => $category_counts,
			'filters'         => array(
				'category' => $category,
				'status'   => $status,
				'sort_by'  => $sort_by,
				'order'    => $order,
			),
			'storage_type'    => $use_cct ? 'cct' : 'cpt',
		);
	}

	/**
	 * Format template data from CCT
	 *
	 * @param array $template Template data.
	 * @return array
	 */
	private function format_template_cct( $template ) {
		// Extract placeholders.
		preg_match_all( '/\{\{(\w+)\}\}/', $template['markdown_template'] ?? '', $matches );
		$placeholders = array_unique( $matches[1] ?? array() );

		return array(
			'template_id'         => $template['_ID'] ?? 0,
			'template_name'       => $template['template_name'] ?? '',
			'description'         => $template['description'] ?? '',
			'category'            => $template['category'] ?? 'custom',
			'status'              => $template['status'] ?? 'draft',
			'version'             => $template['version'] ?? '1.0.0',
			'usage_count'         => intval( $template['usage_count'] ?? 0 ),
			'success_rate'        => floatval( $template['success_rate'] ?? 0 ),
			'avg_completion_time' => intval( $template['avg_completion_time'] ?? 0 ),
			'tags'                => array_filter( explode( ', ', $template['tags'] ?? '' ) ),
			'placeholders'        => $placeholders,
			'author_id'           => intval( $template['author_id'] ?? 0 ),
			'created'             => $template['cct_created'] ?? '',
			'modified'            => $template['cct_modified'] ?? '',
		);
	}

	/**
	 * Format template data from CPT
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function format_template_cpt( $post ) {
		// Extract placeholders.
		preg_match_all( '/\{\{(\w+)\}\}/', $post->post_content, $matches );
		$placeholders = array_unique( $matches[1] ?? array() );

		$status_map = array(
			'draft'   => 'draft',
			'publish' => 'published',
			'private' => 'archived',
		);

		return array(
			'template_id'         => $post->ID,
			'template_name'       => $post->post_title,
			'description'         => $post->post_excerpt,
			'category'            => get_post_meta( $post->ID, 'category', true ) ?: 'custom',
			'status'              => $status_map[ $post->post_status ] ?? 'draft',
			'version'             => get_post_meta( $post->ID, 'version', true ) ?: '1.0.0',
			'usage_count'         => intval( get_post_meta( $post->ID, 'usage_count', true ) ?: 0 ),
			'success_rate'        => floatval( get_post_meta( $post->ID, 'success_rate', true ) ?: 0 ),
			'avg_completion_time' => intval( get_post_meta( $post->ID, 'avg_completion_time', true ) ?: 0 ),
			'tags'                => get_post_meta( $post->ID, 'tags', true ) ?: array(),
			'placeholders'        => $placeholders,
			'author_id'           => intval( $post->post_author ),
			'created'             => $post->post_date,
			'modified'            => $post->post_modified,
		);
	}

	/**
	 * Map sort field for CCT
	 *
	 * @param string $sort_by Sort field.
	 * @return string
	 */
	private function map_sort_field_cct( $sort_by ) {
		$map = array(
			'name'         => 'template_name',
			'usage_count'  => 'usage_count',
			'success_rate' => 'success_rate',
			'created'      => 'cct_created',
			'modified'     => 'cct_modified',
		);
		return $map[ $sort_by ] ?? 'usage_count';
	}

	/**
	 * Map sort field for CPT
	 *
	 * @param string $sort_by Sort field.
	 * @return string
	 */
	private function map_sort_field_cpt( $sort_by ) {
		$map = array(
			'name'         => 'title',
			'usage_count'  => 'meta_value_num',
			'success_rate' => 'meta_value_num',
			'created'      => 'date',
			'modified'     => 'modified',
		);

		if ( in_array( $sort_by, array( 'usage_count', 'success_rate' ), true ) ) {
			return 'meta_value_num';
		}

		return $map[ $sort_by ] ?? 'meta_value_num';
	}

	/**
	 * Check if should use CCT
	 *
	 * @return bool
	 */
	private function should_use_cct() {
		if ( ! class_exists( 'Jet_Engine' ) ) {
			return false;
		}
		if ( ! class_exists( 'WP_MCP_AI_Task_Templates_CCT' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_project_settings', array() );
		return ! empty( $settings['use_cct_storage'] );
	}
}
