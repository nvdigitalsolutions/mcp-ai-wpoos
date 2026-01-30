<?php
/**
 * Tool for creating media collections.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create a new media collection via AI.
 */
class WP_MCP_AI_Tool_Create_Media_Collection implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_media_collection';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Media Collection', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new media collection or update an existing collection. If collection_id is provided, updates the existing collection instead of creating a new one. Collections are used for grouping images and applying templates in batch. Collections can contain multiple images and have templates assigned for consistent processing. Returns the collection ID and details. Use this tool for both creating new collections and updating existing ones.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'collection_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional collection ID. If provided, updates the existing collection instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'title'        => array(
					'type'        => 'string',
					'description' => __( 'Collection title', 'mcp-ai-wpoos-pro' ),
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'Optional collection description', 'mcp-ai-wpoos-pro' ),
				),
				'items'        => array(
					'type'        => 'array',
					'description' => __( 'Array of attachment IDs to include in the collection', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'template_ids' => array(
					'type'        => 'array',
					'description' => __( 'Optional array of template IDs to assign to this collection', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'categories'   => array(
					'type'        => 'array',
					'description' => __( 'Optional array of category slugs or names to assign', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'title' ),
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
			return new WP_Error(
				'media_toolkit_disabled',
				__( 'Media Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate required parameters.
		if ( empty( $arguments['title'] ) ) {
			return new WP_Error(
				'missing_title',
				__( 'Collection title is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if this is an update operation.
		$collection_id       = isset( $arguments['collection_id'] ) ? absint( $arguments['collection_id'] ) : 0;
		$is_update           = false;
		$existing_collection = null;

		if ( $collection_id ) {
			// Verify collection exists and user has permission to update it.
			$existing_collection = get_post( $collection_id );

			if ( ! $existing_collection || 'mcp_ai_media_coll' !== $existing_collection->post_type ) {
				return new WP_Error( 'wp_mcp_ai_collection_not_found', __( 'Media collection not found.', 'mcp-ai-wpoos-pro' ) );
			}

			// Check permissions: must be author or have upload_files capability.
			$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			$is_author       = absint( $existing_collection->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this collection.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_update = true;
		}

		// Sanitize title and description.
		$title       = sanitize_text_field( $arguments['title'] );
		$description = ! empty( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';

		if ( $is_update ) {
			// Update existing collection.
			$collection_data = array(
				'ID'           => $collection_id,
				'post_title'   => $title,
				'post_content' => $description,
			);

			$result = wp_update_post( $collection_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			// Create the collection post.
			$collection_data = array(
				'post_type'    => 'mcp_ai_media_coll',
				'post_title'   => $title,
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_author'  => ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id(),
			);

			$collection_id = wp_insert_post( $collection_data, true );

			if ( is_wp_error( $collection_id ) ) {
				return $collection_id;
			}
		}

		// Add items (attachment IDs) if provided.
		if ( ! empty( $arguments['items'] ) && is_array( $arguments['items'] ) ) {
			$valid_items = array();
			foreach ( $arguments['items'] as $item_id ) {
				$item_id = absint( $item_id );
				if ( $item_id > 0 && 'attachment' === get_post_type( $item_id ) ) {
					$valid_items[] = $item_id;
				}
			}
			if ( ! empty( $valid_items ) ) {
				update_post_meta( $collection_id, '_mcp_ai_collection_items', $valid_items );
			}
		}

		// Add template IDs if provided.
		if ( ! empty( $arguments['template_ids'] ) && is_array( $arguments['template_ids'] ) ) {
			$valid_templates = array();
			foreach ( $arguments['template_ids'] as $template_id ) {
				$template_id = absint( $template_id );
				if ( $template_id > 0 && 'mcp_ai_media_tpl' === get_post_type( $template_id ) ) {
					$valid_templates[] = $template_id;
				}
			}
			if ( ! empty( $valid_templates ) ) {
				update_post_meta( $collection_id, '_mcp_ai_collection_templates', $valid_templates );
			}
		}

		// Add categories if provided.
		if ( ! empty( $arguments['categories'] ) && is_array( $arguments['categories'] ) ) {
			$term_ids = array();
			foreach ( $arguments['categories'] as $category ) {
				$term = term_exists( $category, 'mcp_ai_coll_category' );
				if ( ! $term ) {
					$term = wp_insert_term( $category, 'mcp_ai_coll_category' );
				}
				if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
					$term_ids[] = $term['term_id'];
				}
			}
			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $collection_id, $term_ids, 'mcp_ai_coll_category' );
			}
		}

		// Get collection details for response.
		$items     = get_post_meta( $collection_id, '_mcp_ai_collection_items', true );
		$templates = get_post_meta( $collection_id, '_mcp_ai_collection_templates', true );

		return array(
			'success'       => true,
			'collection_id' => $collection_id,
			'title'         => $title,
			'description'   => $description,
			'item_count'    => is_array( $items ) ? count( $items ) : 0,
			'items'         => is_array( $items ) ? $items : array(),
			'template_ids'  => is_array( $templates ) ? $templates : array(),
			'edit_url'      => admin_url( 'post.php?post=' . $collection_id . '&action=edit' ),
			'updated'       => $is_update,
			'message'       => $is_update
				? sprintf(
					/* translators: %s: Collection title */
					__( 'Media collection "%s" updated successfully.', 'mcp-ai-wpoos-pro' ),
					$title
				)
				: sprintf(
					/* translators: %s: Collection title */
					__( 'Media collection "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
					$title
				),
		);
	}
}
