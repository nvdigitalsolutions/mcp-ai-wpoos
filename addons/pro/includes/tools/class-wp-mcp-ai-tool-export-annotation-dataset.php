<?php
/**
 * Tool: `export_annotation_dataset` (Pro).
 *
 * Proposal 091, Track E — exports the site's chat annotations as an eval
 * dataset consumable by the base plugin's `run_assistant_eval` tool. The
 * dataset rows carry transcript coordinates (transcript id + turn) so the
 * consumer can pull the full exchange from whichever transcript store the
 * site uses.
 *
 * @package WP_MCP_AI
 * @since   1.2.13
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export the annotation eval dataset.
 *
 * @since 1.2.13
 */
class WP_MCP_AI_Tool_Export_Annotation_Dataset implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_annotation_dataset';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Annotation Dataset', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Exports chat annotations (ratings and comments recorded on transcript turns) as an eval dataset consumable by run_assistant_eval. Rows carry transcript coordinates so the consumer can fetch the full exchange. Optionally filter to a single assistant.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Building an eval dataset from human-rated chat turns, or auditing annotation coverage before running an evaluation.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Reading a single transcript (use the transcript tools) or running an evaluation itself (use run_assistant_eval).', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'run_assistant_eval', 'get_chat_transcript', 'record_annotation' ),
			'notes'           => __( 'Rows are returned in JSON; ratings use 1 (positive), 0 (neutral) and -1 (negative).', 'mcp-ai-wpoos-pro' ),
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
	public function get_capability_flags() {
		return array( 'read-only', 'requires-capability', 'pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'assistant_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Optional assistant filter — only annotations on transcripts owned by this assistant are included.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER,
				),
				'confirm_destructive' => array(
					'type'        => 'boolean',
					'description' => __( 'Confirmation flag (unused by this read-only tool; accepted for gate compatibility).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                => $this->get_name(),
			'description'         => $this->get_description(),
			'required_capability' => $this->get_required_capability(),
			'parameters'          => $this->get_parameters_schema(),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		unset( $context );

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Annotation_Store' ) ) {
			return new WP_Error(
				'wp_mcp_ai_annotation_store_unavailable',
				__( 'The annotation store is not available on this site.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		$assistant_id = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;

		$rows = WP_MCP_AI_Annotation_Store::export_dataset( $assistant_id );
		$json = wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return new WP_Error(
				'wp_mcp_ai_annotation_encode_failed',
				__( 'The annotation dataset could not be encoded as JSON.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		return $this->format_success_response(
			__( 'Annotation eval dataset exported.', 'mcp-ai-wpoos-pro' ),
			array(
				'count'         => count( $rows ),
				'assistant_id'  => $assistant_id,
				'rows'          => $rows,
				'json'          => $json,
				'rating_legend' => array(
					'1'  => __( 'positive', 'mcp-ai-wpoos-pro' ),
					'0'  => __( 'neutral', 'mcp-ai-wpoos-pro' ),
					'-1' => __( 'negative', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}
}
