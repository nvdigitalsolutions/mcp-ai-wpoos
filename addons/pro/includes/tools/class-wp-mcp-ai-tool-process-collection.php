<?php
/**
 * Tool for batch processing a media collection.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch process all items in a media collection using assigned templates.
 */
class WP_MCP_AI_Tool_Process_Collection implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'process_collection';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Process Media Collection', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Batch process all items in a media collection using the collection\'s assigned templates. Each item will be processed by each template in sequence. Returns processing results and statistics.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'ID of the media collection to process', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'template_ids'  => array(
					'type'        => 'array',
					'description' => __( 'Optional array of specific template IDs to use (overrides collection templates)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
			'required'   => array( 'collection_id' ),
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
			'write',                // Modifies media items.
			'state-changing',       // Updates collection statistics.
			'local-only',           // No external API calls (uses internal tools).
			'requires-capability',  // Requires upload_files capability.
			'async',                // May take significant time to process collections.
			'long-running',         // Processing multiple items can take minutes.
			'reversible',           // Changes can be undone via WordPress.
			'consumes-tokens',      // Uses AI via apply_media_template tool.
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

		// Validate arguments.
		$collection_id = isset( $arguments['collection_id'] ) ? absint( $arguments['collection_id'] ) : 0;
		$template_ids  = isset( $arguments['template_ids'] ) && is_array( $arguments['template_ids'] ) ? array_map( 'absint', $arguments['template_ids'] ) : array();

		if ( empty( $collection_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Collection ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Verify collection exists.
		$collection = get_post( $collection_id );
		if ( ! $collection || 'mcp_ai_media_coll' !== $collection->post_type || 'publish' !== $collection->post_status ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid collection ID or collection is not published.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get collection items.
		$items = get_post_meta( $collection_id, '_mcp_ai_collection_items', true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Collection has no items.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get templates to use.
		if ( empty( $template_ids ) ) {
			// Use collection's assigned templates.
			$template_ids = get_post_meta( $collection_id, '_mcp_ai_collection_templates', true );
			if ( ! is_array( $template_ids ) || empty( $template_ids ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Collection has no assigned templates. Provide template_ids or assign templates to the collection.', 'mcp-ai-wpoos-pro' ),
				);
			}
		}

		// Get the apply_media_template tool.
		$registry    = WP_MCP_AI_Tool_Registry::get_instance();
		$apply_tool  = $registry->get_tool( 'apply_media_template' );

		if ( ! $apply_tool ) {
			return array(
				'success' => false,
				'error'   => __( 'Apply Media Template tool is not available.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Process each item with each template.
		$results      = array();
		$success_count = 0;
		$error_count   = 0;

		foreach ( $items as $attachment_id ) {
			// Verify attachment exists.
			if ( ! get_post( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
				$results[] = array(
					'attachment_id' => $attachment_id,
					'success'       => false,
					'error'         => __( 'Invalid or non-image attachment.', 'mcp-ai-wpoos-pro' ),
				);
				$error_count++;
				continue;
			}

			foreach ( $template_ids as $template_id ) {
				// Apply template to item.
				$result = $apply_tool->execute(
					array(
						'template_id'   => $template_id,
						'attachment_id' => $attachment_id,
					),
					$context
				);

				$item_result = array(
					'attachment_id' => $attachment_id,
					'template_id'   => $template_id,
					'success'       => ! empty( $result['success'] ),
				);

				if ( ! empty( $result['success'] ) ) {
					$success_count++;
					$item_result['output_id'] = isset( $result['attachment_id'] ) ? $result['attachment_id'] : null;
					$item_result['output_url'] = isset( $result['url'] ) ? $result['url'] : null;
				} else {
					$error_count++;
					$item_result['error'] = isset( $result['error'] ) ? $result['error'] : __( 'Unknown error', 'mcp-ai-wpoos-pro' );
				}

				$results[] = $item_result;
			}
		}

		// Update collection statistics.
		$process_count = absint( get_post_meta( $collection_id, '_mcp_ai_collection_process_count', true ) );
		update_post_meta( $collection_id, '_mcp_ai_collection_process_count', $process_count + 1 );
		update_post_meta( $collection_id, '_mcp_ai_collection_last_processed', current_time( 'mysql' ) );

		// Build response.
		return array(
			'success'    => true,
			'collection' => array(
				'id'              => $collection_id,
				'title'           => $collection->post_title,
				'process_count'   => $process_count + 1,
			),
			'statistics' => array(
				'total_operations' => count( $results ),
				'items_processed'  => count( $items ),
				'templates_used'   => count( $template_ids ),
				'success_count'    => $success_count,
				'error_count'      => $error_count,
			),
			'results'    => $results,
			'message'    => sprintf(
				/* translators: 1: success count, 2: total operations */
				__( 'Collection processed: %1$d of %2$d operations successful.', 'mcp-ai-wpoos-pro' ),
				$success_count,
				count( $results )
			),
		);
	}
}
