<?php
/**
 * Tool: docs_hub_read_doc
 *
 * Reads a Markdown/text document from the NV oOS Docs Hub uploads content
 * folder (wp-content/uploads/nvoos-docs-hub/content/) and returns its raw
 * content.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation_Toolkit
 * @since 2.10.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-docs-hub-helper.php';

/**
 * WP_MCP_AI_Tool_Docs_Hub_Read_Doc tool.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_Docs_Hub_Read_Doc implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'docs_hub_read_doc';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Docs Hub: Read Document', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Reads the raw content of a Markdown or text file from the Docs Hub uploads content folder.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Reviewing or editing an existing Docs Hub page before updating it via docs_hub_write_doc.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Discovering which files exist; use docs_hub_list_docs for that.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'docs_hub_list_docs', 'docs_hub_write_doc', 'docs_hub_delete_doc' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'path' => array(
					'type'        => 'string',
					'description' => __( 'Relative file path inside the Docs Hub content folder, ending in .md or .txt.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'path' ),
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
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'document_generation',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'document_manager', 'technical_writer' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @since 2.10.0
	 * @return bool
	 */
	public static function is_available() {
		return WP_MCP_AI_Docs_Hub_Helper::toolkit_enabled();
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Docs Hub: Read Document tool requires the Document Generation Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! WP_MCP_AI_Docs_Hub_Helper::toolkit_enabled() ) {
			return new WP_Error( 'wp_mcp_ai_toolkit_disabled', __( 'Document Generation Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to read Docs Hub documents.', 'mcp-ai-wpoos-pro' ) );
		}

		// Gate 1 — sanitise at entry.
		$relative = WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path( isset( $arguments['path'] ) ? $arguments['path'] : '' );
		if ( '' === $relative ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_invalid_path', __( 'Invalid document path. Provide a relative path ending in .md or .txt.', 'mcp-ai-wpoos-pro' ) );
		}

		$absolute = WP_MCP_AI_Docs_Hub_Helper::resolve_safe_path( $relative );
		if ( '' === $absolute ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_unsafe_path', __( 'The document path resolves outside the Docs Hub content folder.', 'mcp-ai-wpoos-pro' ) );
		}

		$content = WP_MCP_AI_Docs_Hub_Helper::read_file( $absolute );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return $this->format_success_response(
			sprintf(
				/* translators: %s: relative path. */
				__( 'Docs Hub document %s read.', 'mcp-ai-wpoos-pro' ),
				esc_html( $relative )
			),
			array(
				'path'            => $relative,
				'content'         => $content,
				'size'            => strlen( $content ),
				'modified'        => gmdate( 'c', (int) filemtime( $absolute ) ),
				'title'           => WP_MCP_AI_Docs_Hub_Helper::extract_frontmatter_title( $content ),
				'docs_hub_active' => WP_MCP_AI_Docs_Hub_Helper::is_docs_hub_active(),
			)
		);
	}
}
