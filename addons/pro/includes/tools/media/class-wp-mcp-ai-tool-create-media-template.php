<?php
/**
 * Tool for creating media templates.
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
 * Create a new media template via AI.
 */
class WP_MCP_AI_Tool_Create_Media_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_media_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Media Template', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new media template or update an existing template. If template_id is provided, updates the existing template instead of creating a new one. Templates are used for the Graphic Editor Plus tool and store reusable operation configurations for consistent image processing. Returns the template ID and details. Use this tool for both creating new templates and updating existing ones.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'template_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional template ID. If provided, updates the existing template instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Template title', 'mcp-ai-wpoos-pro' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Optional template description', 'mcp-ai-wpoos-pro' ),
				),
				'operation'   => array(
					'type'        => 'string',
					'description' => __( 'Operation type: add_logo, resize_graphic, expand_scene, ai_enhance, ai_style, ai_background, ai_retouch', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'add_logo', 'resize_graphic', 'expand_scene', 'ai_enhance', 'ai_style', 'ai_background', 'ai_retouch' ),
				),
				'parameters'  => array(
					'type'        => 'object',
					'description' => __( 'Operation parameters as JSON object (see Graphic Editor Plus tool for valid parameters per operation)', 'mcp-ai-wpoos-pro' ),
				),
				'categories'  => array(
					'type'        => 'array',
					'description' => __( 'Optional array of category slugs or names to assign', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'title', 'operation', 'parameters' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'media_content',
			'post_type'             => 'mcp_ai_media_tpl',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'content_creator', 'designer', 'marketer' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Creates posts.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires post creation capabilities.
			'state-changing',       // Modifies database state.
			'reversible',           // Can be deleted via WordPress trash.
			'idempotent',           // Multiple calls with same data safe.
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_media_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Media Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate required arguments.
		$title      = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$operation  = isset( $arguments['operation'] ) ? sanitize_text_field( $arguments['operation'] ) : '';
		$parameters = isset( $arguments['parameters'] ) ? $arguments['parameters'] : array();

		// Check if this is an update operation.
		$template_id       = isset( $arguments['template_id'] ) ? absint( $arguments['template_id'] ) : 0;
		$is_update         = false;
		$existing_template = null;

		if ( $template_id ) {
			// Verify template exists and user has permission to update it.
			$existing_template = get_post( $template_id );

			if ( ! $existing_template || 'mcp_ai_media_tpl' !== $existing_template->post_type ) {
				return array(
					'success' => false,
					'error'   => __( 'Media template not found.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			$is_author       = absint( $existing_template->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return array(
					'success' => false,
					'error'   => __( 'You do not have permission to update this template.', 'mcp-ai-wpoos-pro' ),
				);
			}

			$is_update = true;
		}

		if ( empty( $title ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Template title is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( empty( $operation ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Operation type is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate operation type.
		$valid_operations = array( 'add_logo', 'resize_graphic', 'expand_scene', 'ai_enhance', 'ai_style', 'ai_background', 'ai_retouch' );
		if ( ! in_array( $operation, $valid_operations, true ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: comma-separated list of valid operations */
					__( 'Invalid operation type. Valid operations: %s', 'mcp-ai-wpoos-pro' ),
					implode( ', ', $valid_operations )
				),
			);
		}

		// Validate parameters.
		if ( ! is_array( $parameters ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Parameters must be an object/array.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Optional fields.
		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$categories  = isset( $arguments['categories'] ) && is_array( $arguments['categories'] ) ? $arguments['categories'] : array();

		// Get current user.
		$user_id = get_current_user_id();
		if ( empty( $user_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'User not authenticated.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( $is_update ) {
			// Update existing template.
			$post_data = array(
				'ID'           => $template_id,
				'post_title'   => $title,
				'post_content' => $description,
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: error message */
						__( 'Failed to update template: %s', 'mcp-ai-wpoos-pro' ),
						$result->get_error_message()
					),
				);
			}
		} else {
			// Create the template post.
			$post_data = array(
				'post_type'    => 'mcp_ai_media_tpl',
				'post_title'   => $title,
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_author'  => $user_id,
			);

			$template_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $template_id ) ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: error message */
						__( 'Failed to create template: %s', 'mcp-ai-wpoos-pro' ),
						$template_id->get_error_message()
					),
				);
			}

			// Initialize usage tracking for new templates.
			update_post_meta( $template_id, '_mcp_ai_template_usage_count', 0 );
			update_post_meta( $template_id, '_mcp_ai_template_last_used', '' );
		}

		// Save/update template meta.
		update_post_meta( $template_id, '_mcp_ai_template_operation', $operation );
		update_post_meta( $template_id, '_mcp_ai_template_parameters', wp_json_encode( $parameters ) );

		// Assign categories.
		if ( ! empty( $categories ) ) {
			$term_ids = array();
			foreach ( $categories as $category ) {
				$category = sanitize_text_field( $category );
				// Try to find existing term by slug or name.
				$term = get_term_by( 'slug', $category, 'mcp_ai_tpl_category' );
				if ( ! $term ) {
					$term = get_term_by( 'name', $category, 'mcp_ai_tpl_category' );
				}
				// Create if doesn't exist.
				if ( ! $term ) {
					$result = wp_insert_term( $category, 'mcp_ai_tpl_category' );
					if ( ! is_wp_error( $result ) ) {
						$term_ids[] = $result['term_id'];
					}
				} else {
					$term_ids[] = $term->term_id;
				}
			}
			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $template_id, $term_ids, 'mcp_ai_tpl_category' );
			}
		}

		// Get assigned categories for response.
		$assigned_categories = array();
		$terms               = wp_get_object_terms( $template_id, 'mcp_ai_tpl_category' );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				$assigned_categories[] = array(
					'slug' => $term->slug,
					'name' => $term->name,
				);
			}
		}

		// Return success with template details.
		return array(
			'success'     => true,
			'template_id' => $template_id,
			'template'    => array(
				'id'          => $template_id,
				'title'       => $title,
				'description' => $description,
				'operation'   => $operation,
				'parameters'  => $parameters,
				'categories'  => $assigned_categories,
				'usage_count' => $is_update ? get_post_meta( $template_id, '_mcp_ai_template_usage_count', true ) : 0,
				'last_used'   => $is_update ? get_post_meta( $template_id, '_mcp_ai_template_last_used', true ) : null,
				'created'     => get_the_date( 'c', $template_id ),
			),
			'updated'     => $is_update,
			'message'     => $is_update
				? sprintf(
					/* translators: %s: template title */
					__( 'Media template "%s" updated successfully.', 'mcp-ai-wpoos-pro' ),
					$title
				)
				: sprintf(
					/* translators: %s: template title */
					__( 'Media template "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
					$title
				),
		);
	}
}
