<?php
/**
 * Tool: docs_hub_write_doc
 *
 * Creates or updates a Markdown/text document in the NV oOS Docs Hub uploads
 * content folder (wp-content/uploads/nvoos-docs-hub/content/) so it is
 * published by the documentation browser after a rebuild.
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
 * WP_MCP_AI_Tool_Docs_Hub_Write_Doc tool.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_Docs_Hub_Write_Doc implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'docs_hub_write_doc';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Docs Hub: Write Document', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates or updates a Markdown or text file in the Docs Hub uploads content folder, with optional YAML frontmatter (title, slug, order) and an automatic index rebuild.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Publishing wiki pages, guides, or runbooks into the Docs Hub documentation browser by writing .md/.txt files into its uploads content folder.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Generating PDF/Word/Excel deliverables; use generate_pdf, generate_word or generate_excel for those. Reading existing files should use docs_hub_read_doc instead.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'docs_hub_read_doc', 'docs_hub_list_docs', 'docs_hub_delete_doc', 'docs_hub_rebuild' ),
			'notes'           => __( 'Paths are relative to the Docs Hub content folder (e.g. guides/getting-started.md) and are validated against traversal. A rebuild is triggered by default; it degrades gracefully when the Docs Hub plugin is inactive.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'path'        => array(
					'type'        => 'string',
					'description' => __( 'Relative file path inside the Docs Hub content folder, ending in .md or .txt (e.g. guides/getting-started.md).', 'mcp-ai-wpoos-pro' ),
				),
				'content'     => array(
					'type'        => 'string',
					'description' => __( 'Markdown or plain-text file content.', 'mcp-ai-wpoos-pro' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Optional page title stored in the YAML frontmatter; drives the Docs Hub sidebar label.', 'mcp-ai-wpoos-pro' ),
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => __( 'Optional canonical slug override for the frontmatter.', 'mcp-ai-wpoos-pro' ),
				),
				'order'       => array(
					'type'        => 'integer',
					'description' => __( 'Optional sidebar sort order for the frontmatter. Lower values sort first.', 'mcp-ai-wpoos-pro' ),
				),
				'frontmatter' => array(
					'type'        => 'object',
					'description' => __( 'Optional additional scalar key/value pairs for the YAML frontmatter.', 'mcp-ai-wpoos-pro' ),
				),
				'overwrite'   => array(
					'type'        => 'boolean',
					'description' => __( 'Allow replacing an existing file. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'rebuild'     => array(
					'type'        => 'boolean',
					'description' => __( 'Trigger a Docs Hub index rebuild after writing. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'path', 'content' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
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
			'idempotent',
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
		return __( 'The Docs Hub: Write Document tool requires the Document Generation Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
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

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to write Docs Hub documents.', 'mcp-ai-wpoos-pro' ) );
		}

		// Gate 1 — sanitise at entry.
		$relative = WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path( isset( $arguments['path'] ) ? $arguments['path'] : '' );
		if ( '' === $relative ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_invalid_path', __( 'Invalid document path. Provide a relative path ending in .md or .txt (e.g. guides/getting-started.md).', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! isset( $arguments['content'] ) || ! is_string( $arguments['content'] ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_invalid_content', __( 'The content argument must be a string.', 'mcp-ai-wpoos-pro' ) );
		}

		$content   = wp_check_invalid_utf8( $arguments['content'] );
		$title     = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$slug      = isset( $arguments['slug'] ) ? sanitize_title( $arguments['slug'] ) : '';
		$order     = isset( $arguments['order'] ) ? absint( $arguments['order'] ) : null;
		$extra     = isset( $arguments['frontmatter'] ) && is_array( $arguments['frontmatter'] ) ? $arguments['frontmatter'] : array();
		$overwrite = ! isset( $arguments['overwrite'] ) || (bool) $arguments['overwrite'];
		$rebuild   = ! isset( $arguments['rebuild'] ) || (bool) $arguments['rebuild'];

		$frontmatter = WP_MCP_AI_Docs_Hub_Helper::build_frontmatter( $title, $slug, $order, $extra );
		$payload     = $frontmatter . $content;

		if ( strlen( $payload ) > WP_MCP_AI_Docs_Hub_Helper::MAX_FILE_SIZE ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_file_too_large', __( 'The document exceeds the 2 MB size limit.', 'mcp-ai-wpoos-pro' ) );
		}

		// Resolve the target with traversal protection.
		$absolute = WP_MCP_AI_Docs_Hub_Helper::resolve_safe_path( $relative );
		if ( '' === $absolute ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_unsafe_path', __( 'The document path resolves outside the Docs Hub content folder.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( file_exists( $absolute ) && ! $overwrite ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_exists', __( 'The document already exists. Pass overwrite=true to replace it.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_dir( $absolute ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_is_directory', __( 'The requested path is a directory.', 'mcp-ai-wpoos-pro' ) );
		}

		// Ensure the parent directory exists before writing, then re-verify
		// the realpath stays inside the content root (guards against a
		// symlinked subdirectory created between resolve and write).
		$parent = dirname( $absolute );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_mkdir_failed', __( 'The target directory could not be created.', 'mcp-ai-wpoos-pro' ) );
		}

		$real_root   = realpath( WP_MCP_AI_Docs_Hub_Helper::content_dir() );
		$real_parent = realpath( $parent );
		if ( false === $real_root || false === $real_parent || ( $real_parent !== $real_root && 0 !== strpos( $real_parent, $real_root . DIRECTORY_SEPARATOR ) ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_unsafe_path', __( 'The document path resolves outside the Docs Hub content folder.', 'mcp-ai-wpoos-pro' ) );
		}

		$existed = file_exists( $absolute );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- direct local write; path was validated against the content root, best-effort.
		$written = @file_put_contents( $absolute, $payload );
		if ( false === $written ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_write_failed', __( 'The document could not be written to the Docs Hub content folder.', 'mcp-ai-wpoos-pro' ) );
		}

		$data = array(
			'path'            => $relative,
			'full_path'       => $absolute,
			'bytes'           => $written,
			'action'          => $existed ? 'updated' : 'created',
			'frontmatter'     => array_filter(
				array(
					'title' => $title,
					'slug'  => $slug,
					'order' => $order,
				),
				static function ( $value ) {
					return null !== $value && '' !== $value;
				}
			),
			'docs_hub_active' => WP_MCP_AI_Docs_Hub_Helper::is_docs_hub_active(),
			'rebuild'         => array(
				'requested' => $rebuild,
				'status'    => 'skipped',
			),
		);

		if ( $rebuild ) {
			$summary = WP_MCP_AI_Docs_Hub_Helper::enqueue_rebuild();
			if ( null !== $summary ) {
				$data['rebuild'] = array_merge(
					array(
						'requested' => true,
						'status'    => 'queued',
					),
					$summary
				);
			} elseif ( ! $data['docs_hub_active'] ) {
				$data['rebuild']['reason'] = __( 'Docs Hub plugin is not active; rebuild will run on activation or manually.', 'mcp-ai-wpoos-pro' );
			}
		}

		return $this->format_success_response(
			sprintf(
				/* translators: 1: relative path, 2: created/updated. */
				__( 'Docs Hub document %1$s %2$s.', 'mcp-ai-wpoos-pro' ),
				esc_html( $relative ),
				esc_html( $data['action'] )
			),
			$data
		);
	}
}
