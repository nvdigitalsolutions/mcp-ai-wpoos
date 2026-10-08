<?php
/**
 * Tool: docs_hub_list_docs
 *
 * Lists the Markdown/text documents in the NV oOS Docs Hub uploads content
 * folder (wp-content/uploads/nvoos-docs-hub/content/), optionally with their
 * raw content.
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
 * WP_MCP_AI_Tool_Docs_Hub_List_Docs tool.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_Docs_Hub_List_Docs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'docs_hub_list_docs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Docs Hub: List Documents', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists the Markdown and text files in the Docs Hub uploads content folder, with size, modified time and frontmatter title.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Inventorying the Docs Hub content folder before writing, updating or deleting pages.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Reading a single known file; use docs_hub_read_doc for that.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'docs_hub_read_doc', 'docs_hub_write_doc', 'docs_hub_delete_doc' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subdirectory'    => array(
					'type'        => 'string',
					'description' => __( 'Optional subdirectory of the Docs Hub content folder to restrict the listing to.', 'mcp-ai-wpoos-pro' ),
				),
				'include_content' => array(
					'type'        => 'boolean',
					'description' => __( 'Include each file\'s raw content in the result. Default: false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of files to return. Default: 100.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 100,
				),
			),
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
		return __( 'The Docs Hub: List Documents tool requires the Document Generation Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list Docs Hub documents.', 'mcp-ai-wpoos-pro' ) );
		}

		// Gate 1 — sanitise at entry.
		$subdirectory = isset( $arguments['subdirectory'] ) ? WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path( $arguments['subdirectory'], false ) : '';
		if ( isset( $arguments['subdirectory'] ) && '' === $subdirectory && '' !== trim( (string) $arguments['subdirectory'] ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_invalid_path', __( 'Invalid subdirectory path.', 'mcp-ai-wpoos-pro' ) );
		}

		$include_content = ! empty( $arguments['include_content'] );
		$limit           = isset( $arguments['limit'] ) ? min( 500, max( 1, absint( $arguments['limit'] ) ) ) : 100;

		$root = WP_MCP_AI_Docs_Hub_Helper::content_dir();

		$dir = '' !== $subdirectory ? $root . '/' . $subdirectory : $root;
		if ( ! is_dir( $dir ) ) {
			// Nothing written yet: report an empty inventory.
			return $this->format_success_response(
				__( 'Docs Hub content folder is empty.', 'mcp-ai-wpoos-pro' ),
				array(
					'count'           => 0,
					'files'           => array(),
					'content_dir'     => $root,
					'docs_hub_active' => WP_MCP_AI_Docs_Hub_Helper::is_docs_hub_active(),
					'uploads_enabled' => WP_MCP_AI_Docs_Hub_Helper::is_uploads_source_enabled(),
				)
			);
		}

		// Resolve the listing root and refuse to walk a symlinked directory.
		$real_dir  = realpath( $dir );
		$real_root = realpath( WP_MCP_AI_Docs_Hub_Helper::content_dir() );
		if ( false === $real_dir || false === $real_root ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_resolve_failed', __( 'The Docs Hub content folder could not be resolved.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $real_dir !== $real_root && 0 !== strpos( $real_dir, $real_root . DIRECTORY_SEPARATOR ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_unsafe_path', __( 'The subdirectory resolves outside the Docs Hub content folder.', 'mcp-ai-wpoos-pro' ) );
		}

		$files = WP_MCP_AI_Docs_Hub_Helper::collect_doc_files( $real_dir );
		sort( $files );

		$entries = array();
		foreach ( $files as $file ) {
			if ( count( $entries ) >= $limit ) {
				break;
			}

			$relative = ltrim( str_replace( $real_root, '', $file ), DIRECTORY_SEPARATOR );
			$relative = str_replace( DIRECTORY_SEPARATOR, '/', $relative );

			$size = filesize( $file );

			$entry = array(
				'path'     => $relative,
				'size'     => false === $size ? 0 : (int) $size,
				'modified' => gmdate( 'c', (int) filemtime( $file ) ),
			);

			if ( $include_content && false !== $size && $size <= WP_MCP_AI_Docs_Hub_Helper::MAX_FILE_SIZE ) {
				$content = WP_MCP_AI_Docs_Hub_Helper::read_file( $file );
				if ( is_wp_error( $content ) ) {
					$entry['content_error'] = $content->get_error_message();
				} else {
					$entry['content'] = $content;
					$entry['title']   = WP_MCP_AI_Docs_Hub_Helper::extract_frontmatter_title( $content );
				}
			} elseif ( $include_content ) {
				$entry['content_error'] = __( 'File exceeds the 2 MB size limit.', 'mcp-ai-wpoos-pro' );
			}

			$entries[] = $entry;
		}

		// Sort alphabetically by relative path.
		usort(
			$entries,
			static function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		return $this->format_success_response(
			sprintf(
				/* translators: %d: file count. */
				__( 'Listed %d Docs Hub document(s).', 'mcp-ai-wpoos-pro' ),
				count( $entries )
			),
			array(
				'count'           => count( $entries ),
				'truncated'       => count( $files ) > $limit,
				'files'           => $entries,
				'content_dir'     => $root,
				'docs_hub_active' => WP_MCP_AI_Docs_Hub_Helper::is_docs_hub_active(),
				'uploads_enabled' => WP_MCP_AI_Docs_Hub_Helper::is_uploads_source_enabled(),
			)
		);
	}
}
