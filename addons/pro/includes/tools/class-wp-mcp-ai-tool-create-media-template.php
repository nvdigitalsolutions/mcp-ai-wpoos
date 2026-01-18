<?php
/**
 * Tool for creating media templates.
 *
 * @package WP_MCP_AI
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
		return __( 'Create a new media template for the Graphic Editor Plus tool. Templates store reusable operation configurations for consistent image processing. Returns the created template ID and details.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
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

		// Save template meta.
		update_post_meta( $template_id, '_mcp_ai_template_operation', $operation );
		update_post_meta( $template_id, '_mcp_ai_template_parameters', wp_json_encode( $parameters ) );
		update_post_meta( $template_id, '_mcp_ai_template_usage_count', 0 );
		update_post_meta( $template_id, '_mcp_ai_template_last_used', '' );

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
				'usage_count' => 0,
				'last_used'   => null,
				'created'     => get_the_date( 'c', $template_id ),
			),
			'message'     => sprintf(
				/* translators: %s: template title */
				__( 'Media template "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
				$title
			),
		);
	}
}
