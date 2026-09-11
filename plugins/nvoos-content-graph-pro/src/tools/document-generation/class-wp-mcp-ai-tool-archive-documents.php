<?php
/**
 * WP_MCP_AI_Tool_Archive_Documents (ecosystem port - Wave F2, document-generation tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/document-generation/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger/trait requires gain exists-check seams resolving from the addon's D8-compat `src/` copies; the monolith-gated openai/gemini/ollama client requires stay monolith-gated (architectural-design precedent).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


/**
 * Archives documents by moving them to an archive status or category.
 *
 * Supports dry_run mode to preview which documents would be affected
 * before making changes.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Tool_Archive_Documents implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'archive_documents';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Archive Documents', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Archives documents by moving them to an archive status or category. Supports dry_run mode to preview changes before applying them.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'document_ids'   => array(
					'type'        => 'array',
					'description' => __( 'Array of document post IDs to archive.', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'archive_reason' => array(
					'type'        => 'string',
					'description' => __( 'Optional reason for archiving (stored in post meta).', 'nvoos-content-graph-pro' ),
				),
				'dry_run'        => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview which documents would be archived without making changes. Default: true.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'document_ids' ),
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
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'document_generation',
			'post_type'             => 'mcp_ai_doc_tpl',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'document_manager' ),
			'risk_level'            => 'caution',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'state-changing',
			'local-only',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Document Generation Toolkit to be enabled.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_document_generation_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.9.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Archive Documents tool requires the Document Generation Toolkit to be enabled in plugin settings.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Archive result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Document Generation Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'nvoos-content-graph-pro' ),
			);
		}

		$document_ids   = isset( $arguments['document_ids'] ) ? array_map( 'absint', (array) $arguments['document_ids'] ) : array();
		$archive_reason = isset( $arguments['archive_reason'] ) ? sanitize_text_field( $arguments['archive_reason'] ) : '';
		$dry_run        = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		if ( empty( $document_ids ) ) {
			return array(
				'success' => false,
				'error'   => __( 'At least one document ID must be provided.', 'nvoos-content-graph-pro' ),
			);
		}

		$now       = gmdate( 'Y-m-d H:i:s' );
		$archived  = array();
		$skipped   = array();
		$not_found = array();

		foreach ( $document_ids as $doc_id ) {
			$post = get_post( $doc_id );
			if ( ! $post || 'mcp_ai_doc_tpl' !== $post->post_type ) {
				$not_found[] = $doc_id;
				continue;
			}

			if ( ! current_user_can( 'edit_post', $doc_id ) ) {
				$skipped[] = array(
					'id'     => $doc_id,
					'title'  => get_the_title( $post ),
					'reason' => __( 'Insufficient permissions.', 'nvoos-content-graph-pro' ),
				);
				continue;
			}

			$result_item = array(
				'id'    => $doc_id,
				'title' => get_the_title( $post ),
			);

			if ( ! $dry_run ) {
				// Update post status to 'archive' if available, otherwise use 'draft'.
				$new_status = post_type_supports( 'mcp_ai_doc_tpl', 'archive' ) ? 'archive' : 'draft';
				$updated    = wp_update_post(
					array(
						'ID'          => $doc_id,
						'post_status' => $new_status,
					),
					true
				);

				if ( is_wp_error( $updated ) ) {
					$skipped[] = array_merge( $result_item, array( 'reason' => $updated->get_error_message() ) );
					continue;
				}

				update_post_meta( $doc_id, '_archived_date', $now );
				if ( ! empty( $archive_reason ) ) {
					update_post_meta( $doc_id, '_archive_reason', $archive_reason );
				}
				update_post_meta( $doc_id, '_archived_by', get_current_user_id() );

				$result_item['new_status'] = $new_status;
			}

			$archived[] = $result_item;
		}

		$result = array(
			'success'         => true,
			'dry_run'         => $dry_run,
			'action'          => $dry_run ? __( 'Dry run completed. No documents were modified.', 'nvoos-content-graph-pro' ) : __( 'Documents archived successfully.', 'nvoos-content-graph-pro' ),
			'archived_count'  => count( $archived ),
			'skipped_count'   => count( $skipped ),
			'not_found_count' => count( $not_found ),
			'documents'       => array(
				'archived'  => $archived,
				'skipped'   => $skipped,
				'not_found' => $not_found,
			),
		);

		if ( ! empty( $archive_reason ) ) {
			$result['archive_reason'] = $archive_reason;
		}

		return $result;
	}
}
