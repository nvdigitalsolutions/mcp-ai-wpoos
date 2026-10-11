<?php
/**
 * Tool: docs_hub_delete_doc
 *
 * Deletes a Markdown/text document from the NV oOS Docs Hub uploads content
 * folder (wp-content/uploads/nvoos-docs-hub/content/), with a dry-run preview
 * by default and an optional index rebuild.
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
 * WP_MCP_AI_Tool_Docs_Hub_Delete_Doc tool.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_Docs_Hub_Delete_Doc implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'docs_hub_delete_doc';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Docs Hub: Delete Document', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a Markdown or text file from the Docs Hub uploads content folder. Dry-run by default; optionally triggers an index rebuild.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Removing retired or duplicate Docs Hub pages from the uploads content folder.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Bulk operations on WordPress media or the document template CPT; use archive_documents for templates.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'docs_hub_list_docs', 'docs_hub_write_doc', 'docs_hub_rebuild' ),
			'notes'           => __( 'Deletion is irreversible — the dry run is on by default. Pass dry_run=false to actually delete.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'path'    => array(
					'type'        => 'string',
					'description' => __( 'Relative file path inside the Docs Hub content folder, ending in .md or .txt.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview the deletion without removing anything. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'rebuild' => array(
					'type'        => 'boolean',
					'description' => __( 'Trigger a Docs Hub index rebuild after deleting. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
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
			'irreversible',
			'data-destruction',
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
		return __( 'The Docs Hub: Delete Document tool requires the Document Generation Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete Docs Hub documents.', 'mcp-ai-wpoos-pro' ) );
		}

		// Gate 1 — sanitise at entry.
		$relative = WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path( isset( $arguments['path'] ) ? $arguments['path'] : '' );
		if ( '' === $relative ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_invalid_path', __( 'Invalid document path. Provide a relative path ending in .md or .txt.', 'mcp-ai-wpoos-pro' ) );
		}

		$dry_run = ! isset( $arguments['dry_run'] ) || (bool) $arguments['dry_run'];
		$rebuild = ! isset( $arguments['rebuild'] ) || (bool) $arguments['rebuild'];

		$absolute = WP_MCP_AI_Docs_Hub_Helper::resolve_safe_path( $relative );
		if ( '' === $absolute ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_unsafe_path', __( 'The document path resolves outside the Docs Hub content folder.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! file_exists( $absolute ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_not_found', __( 'The requested document does not exist.', 'mcp-ai-wpoos-pro' ) );
		}

		// Only ever unlink regular files — never directories or symlinks.
		if ( ! is_file( $absolute ) || is_link( $absolute ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_not_file', __( 'The requested path is not a regular document file.', 'mcp-ai-wpoos-pro' ) );
		}

		$data = array(
			'path'            => $relative,
			'full_path'       => $absolute,
			'size'            => (int) filesize( $absolute ),
			'dry_run'         => $dry_run,
			'docs_hub_active' => WP_MCP_AI_Docs_Hub_Helper::is_docs_hub_active(),
			'rebuild'         => array(
				'requested' => $rebuild && ! $dry_run,
				'status'    => 'skipped',
			),
		);

		if ( $dry_run ) {
			$data['action'] = 'previewed';
			return $this->format_success_response(
				sprintf(
					/* translators: %s: relative path. */
					__( 'Dry run: Docs Hub document %s would be deleted.', 'mcp-ai-wpoos-pro' ),
					esc_html( $relative )
				),
				$data
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- direct local delete; path was validated against the content root, best-effort.
		if ( ! @unlink( $absolute ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_delete_failed', __( 'The document could not be deleted.', 'mcp-ai-wpoos-pro' ) );
		}

		$data['action'] = 'deleted';

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
				/* translators: %s: relative path. */
				__( 'Docs Hub document %s deleted.', 'mcp-ai-wpoos-pro' ),
				esc_html( $relative )
			),
			$data
		);
	}
}
