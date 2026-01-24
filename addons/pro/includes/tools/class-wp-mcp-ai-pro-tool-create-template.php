<?php
/**
 * Tool: Create Template
 *
 * Creates reusable task plan templates with placeholders and default configurations
 *
 * @package MCP_AI_WP_OOS_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create Template Tool Class
 */
class WP_MCP_AI_Pro_Tool_Create_Template {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'create_template';
	}

	/**
	 * Get tool definition for AI
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'         => 'create_template',
			'description'  => 'Creates a reusable task plan template with placeholders for variables, default configurations, and usage tracking. Templates can be used to standardize workflows like research, content creation, data analysis, or custom processes.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'template_name'     => array(
						'type'        => 'string',
						'description' => 'Name of the template',
					),
					'description'       => array(
						'type'        => 'string',
						'description' => 'Description of what this template is for and when to use it',
					),
					'category'          => array(
						'type'        => 'string',
						'description' => 'Template category',
						'enum'        => array( 'research', 'content', 'data_analysis', 'development', 'marketing', 'custom' ),
					),
					'markdown_template' => array(
						'type'        => 'string',
						'description' => 'Markdown template content with placeholders like {{goal}}, {{topic}}, {{count}}, etc. Use GFM checkbox format for tasks.',
					),
					'default_config'    => array(
						'type'        => 'object',
						'description' => 'Default configuration values',
						'properties'  => array(
							'max_iterations' => array(
								'type'        => 'number',
								'description' => 'Default max iterations',
							),
							'token_budget'   => array(
								'type'        => 'number',
								'description' => 'Default token budget',
							),
						),
					),
					'tags'              => array(
						'type'        => 'array',
						'description' => 'Tags for template organization',
						'items'       => array( 'type' => 'string' ),
					),
					'version'           => array(
						'type'        => 'string',
						'description' => 'Template version (e.g., 1.0.0)',
					),
				),
				'required'   => array( 'template_name', 'description', 'category', 'markdown_template' ),
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
	public function execute( $arguments, $context ) {
		// Extract arguments.
		$template_name     = sanitize_text_field( $arguments['template_name'] ?? '' );
		$description       = sanitize_textarea_field( $arguments['description'] ?? '' );
		$category          = sanitize_text_field( $arguments['category'] ?? 'custom' );
		$markdown_template = $arguments['markdown_template'] ?? '';
		$default_config    = $arguments['default_config'] ?? array();
		$tags              = $arguments['tags'] ?? array();
		$version           = sanitize_text_field( $arguments['version'] ?? '1.0.0' );

		// Validate required fields.
		if ( empty( $template_name ) || empty( $description ) || empty( $markdown_template ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required fields: template_name, description, and markdown_template are required',
			);
		}

		// Validate category.
		$valid_categories = array( 'research', 'content', 'data_analysis', 'development', 'marketing', 'custom' );
		if ( ! in_array( $category, $valid_categories, true ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid category. Must be one of: ' . implode( ', ', $valid_categories ),
			);
		}

		// Get current user.
		$author_id = get_current_user_id();
		if ( ! $author_id ) {
			$author_id = 1; // Fallback to admin.
		}

		// Check if we should use CCT or CPT.
		$use_cct = $this->should_use_cct();

		if ( $use_cct ) {
			// Create template using CCT.
			$template_data = array(
				'template_name'       => $template_name,
				'description'         => $description,
				'category'            => $category,
				'markdown_template'   => $markdown_template,
				'default_config'      => wp_json_encode( $default_config ),
				'tags'                => is_array( $tags ) ? implode( ', ', $tags ) : '',
				'status'              => 'draft',
				'usage_count'         => 0,
				'success_rate'        => 0,
				'avg_completion_time' => 0,
				'version'             => $version,
				'author_id'           => $author_id,
				'metadata'            => wp_json_encode( array( 'created_at' => current_time( 'mysql' ) ) ),
			);

			$handler = WP_MCP_AI_Task_Templates_CCT::get_item_handler();
			if ( ! $handler ) {
				return array(
					'success' => false,
					'error'   => 'CCT handler not available. Please enable JetEngine.',
				);
			}

			$template_id = $handler->update_item( null, $template_data );

			if ( ! $template_id ) {
				return array(
					'success' => false,
					'error'   => 'Failed to create template in CCT',
				);
			}
		} else {
			// Create template using custom post type.
			$post_data = array(
				'post_title'   => $template_name,
				'post_content' => $markdown_template,
				'post_excerpt' => $description,
				'post_status'  => 'draft',
				'post_type'    => 'mcp_task_template',
				'post_author'  => $author_id,
			);

			$template_id = wp_insert_post( $post_data );

			if ( is_wp_error( $template_id ) ) {
				return array(
					'success' => false,
					'error'   => $template_id->get_error_message(),
				);
			}

			// Store meta data.
			update_post_meta( $template_id, 'category', $category );
			update_post_meta( $template_id, 'default_config', $default_config );
			update_post_meta( $template_id, 'tags', $tags );
			update_post_meta( $template_id, 'version', $version );
			update_post_meta( $template_id, 'usage_count', 0 );
			update_post_meta( $template_id, 'success_rate', 0 );
			update_post_meta( $template_id, 'avg_completion_time', 0 );
		}

		// Extract placeholders from template.
		preg_match_all( '/\{\{(\w+)\}\}/', $markdown_template, $matches );
		$placeholders = array_unique( $matches[1] ?? array() );

		return array(
			'success'       => true,
			'template_id'   => $template_id,
			'template_name' => $template_name,
			'category'      => $category,
			'version'       => $version,
			'placeholders'  => $placeholders,
			'storage_type'  => $use_cct ? 'cct' : 'cpt',
			'message'       => 'Template created successfully. Use this template_id to instantiate task plans.',
		);
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
