<?php
/**
 * Tool: docs_hub_rebuild
 *
 * Triggers an NV oOS Docs Hub index rebuild (synchronous or async chunked)
 * after Markdown/text files in the uploads content folder have changed.
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
 * WP_MCP_AI_Tool_Docs_Hub_Rebuild tool.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_Docs_Hub_Rebuild implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'docs_hub_rebuild';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Docs Hub: Rebuild Index', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Triggers an NV oOS Docs Hub index rebuild after content changes, synchronously or via the async chunked pipeline.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Publishing Docs Hub content changes when the docs_hub_write_doc/delete_doc rebuild flag was skipped, or after manually dropping files into the uploads content folder.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Monitoring progress of an already-queued rebuild; the async summary carries the job id and phase for that.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'docs_hub_write_doc', 'docs_hub_delete_doc', 'docs_hub_list_docs' ),
			'notes'           => __( 'Requires the NV oOS Docs Hub plugin to be active. Async is the default; sync runs inline and returns the page/broken-link counts.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'mode'  => array(
					'type'        => 'string',
					'description' => __( 'Execution mode: async (chunked pipeline, default) or sync (inline, returns final counts).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'async', 'sync' ),
					'default'     => 'async',
				),
				'reset' => array(
					'type'        => 'boolean',
					'description' => __( 'Force-reset a stuck rebuild state before enqueueing (async mode only). Default: false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
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
			'idempotent',
			'performance-impact',
			'async',
			'local-only',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Document Generation Toolkit to be enabled AND the
	 * standalone NV oOS Docs Hub plugin to be active.
	 *
	 * @since 2.10.0
	 * @return bool
	 */
	public static function is_available() {
		return WP_MCP_AI_Docs_Hub_Helper::toolkit_enabled() && class_exists( 'NV_oOS_Docs_Hub_Rebuild_Job' );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		if ( ! WP_MCP_AI_Docs_Hub_Helper::toolkit_enabled() ) {
			return __( 'The Docs Hub: Rebuild Index tool requires the Document Generation Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'The Docs Hub: Rebuild Index tool requires the NV oOS Docs Hub plugin to be installed and active.', 'mcp-ai-wpoos-pro' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to rebuild the Docs Hub index.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'NV_oOS_Docs_Hub_Rebuild_Job' ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_inactive', __( 'The NV oOS Docs Hub plugin is not active; install and activate it to rebuild the documentation index.', 'mcp-ai-wpoos-pro' ) );
		}

		// Gate 1 — sanitise at entry.
		$mode  = isset( $arguments['mode'] ) ? sanitize_key( $arguments['mode'] ) : 'async';
		$reset = ! empty( $arguments['reset'] );

		if ( ! in_array( $mode, array( 'async', 'sync' ), true ) ) {
			$mode = 'async';
		}

		if ( 'sync' === $mode ) {
			$result = NV_oOS_Docs_Hub_Rebuild_Job::run();

			if ( empty( $result['success'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_docs_hub_rebuild_failed',
					isset( $result['error'] ) ? sanitize_text_field( $result['error'] ) : __( 'The Docs Hub index rebuild failed.', 'mcp-ai-wpoos-pro' )
				);
			}

			return $this->format_success_response(
				sprintf(
					/* translators: 1: page count, 2: duration in ms. */
					__( 'Docs Hub index rebuilt: %1$d page(s) in %2$d ms.', 'mcp-ai-wpoos-pro' ),
					(int) $result['pages'],
					(int) $result['duration_ms']
				),
				array(
					'mode'         => 'sync',
					'pages'        => (int) $result['pages'],
					'broken_links' => (int) $result['broken_links'],
					'duration_ms'  => (int) $result['duration_ms'],
				)
			);
		}

		if ( $reset && class_exists( 'NV_oOS_Docs_Hub_Rebuild_State' ) ) {
			NV_oOS_Docs_Hub_Rebuild_State::reset();
		}

		$summary = NV_oOS_Docs_Hub_Rebuild_Job::enqueue_async();

		return $this->format_success_response(
			__( 'Docs Hub rebuild queued.', 'mcp-ai-wpoos-pro' ),
			array_merge(
				array(
					'mode'  => 'async',
					'reset' => $reset,
				),
				$summary
			)
		);
	}
}
