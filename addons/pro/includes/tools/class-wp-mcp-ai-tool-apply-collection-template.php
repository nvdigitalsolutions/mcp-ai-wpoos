<?php
/**
 * Tool for applying templates to a media collection.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply one or more templates to all items in a media collection.
 */
class WP_MCP_AI_Tool_Apply_Collection_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'apply_collection_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Apply Collection Template', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Apply one or more templates to all items in a media collection. This is a convenience method that assigns templates to the collection and then processes it. Returns processing results and updates collection configuration.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'ID of the media collection', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'template_ids'  => array(
					'type'        => 'array',
					'description' => __( 'Array of template IDs to assign and apply', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'append'        => array(
					'type'        => 'boolean',
					'description' => __( 'If true, append to existing templates. If false, replace existing templates. Default: false', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'process'       => array(
					'type'        => 'boolean',
					'description' => __( 'If true, immediately process the collection. If false, only assign templates. Default: true', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'collection_id', 'template_ids' ),
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
	public function execute( $arguments, $context ) {
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
		$append        = isset( $arguments['append'] ) ? (bool) $arguments['append'] : false;
		$process       = isset( $arguments['process'] ) ? (bool) $arguments['process'] : true;

		if ( empty( $collection_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Collection ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( empty( $template_ids ) ) {
			return array(
				'success' => false,
				'error'   => __( 'At least one template ID is required.', 'mcp-ai-wpoos-pro' ),
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

		// Verify all templates exist.
		foreach ( $template_ids as $template_id ) {
			$template = get_post( $template_id );
			if ( ! $template || 'mcp_ai_media_tpl' !== $template->post_type || 'publish' !== $template->post_status ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %d: template ID */
						__( 'Invalid template ID %d or template is not published.', 'mcp-ai-wpoos-pro' ),
						$template_id
					),
				);
			}
		}

		// Get existing templates if appending.
		$final_template_ids = $template_ids;
		if ( $append ) {
			$existing = get_post_meta( $collection_id, '_mcp_ai_collection_templates', true );
			if ( is_array( $existing ) && ! empty( $existing ) ) {
				$final_template_ids = array_unique( array_merge( $existing, $template_ids ) );
			}
		}

		// Update collection templates.
		update_post_meta( $collection_id, '_mcp_ai_collection_templates', $final_template_ids );

		// Build response base.
		$response = array(
			'success'    => true,
			'collection' => array(
				'id'            => $collection_id,
				'title'         => $collection->post_title,
				'template_ids'  => $final_template_ids,
				'templates_assigned' => count( $final_template_ids ),
			),
			'message'    => sprintf(
				/* translators: 1: number of templates, 2: collection title */
				__( 'Assigned %1$d template(s) to collection "%2$s".', 'mcp-ai-wpoos-pro' ),
				count( $final_template_ids ),
				$collection->post_title
			),
		);

		// Process collection if requested.
		if ( $process ) {
			$registry      = WP_MCP_AI_Tool_Registry::get_instance();
			$process_tool  = $registry->get_tool( 'process_collection' );

			if ( ! $process_tool ) {
				$response['warning'] = __( 'Templates assigned but could not process collection: Process Collection tool is not available.', 'mcp-ai-wpoos-pro' );
				return $response;
			}

			// Execute process_collection tool.
			$process_result = $process_tool->execute(
				array(
					'collection_id' => $collection_id,
				),
				$context
			);

			// Merge process results.
			if ( ! empty( $process_result['success'] ) ) {
				$response['processing'] = array(
					'statistics' => isset( $process_result['statistics'] ) ? $process_result['statistics'] : array(),
					'results'    => isset( $process_result['results'] ) ? $process_result['results'] : array(),
				);
				$response['message'] .= ' ' . ( isset( $process_result['message'] ) ? $process_result['message'] : __( 'Collection processed.', 'mcp-ai-wpoos-pro' ) );
			} else {
				$response['warning'] = sprintf(
					/* translators: %s: error message */
					__( 'Templates assigned but processing failed: %s', 'mcp-ai-wpoos-pro' ),
					isset( $process_result['error'] ) ? $process_result['error'] : __( 'Unknown error', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		return $response;
	}
}
