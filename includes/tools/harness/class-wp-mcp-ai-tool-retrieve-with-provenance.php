<?php
/**
 * Tool: retrieve_with_provenance — Layer D facade tool.
 *
 * Single retrieval entry point that fans out to recall_memory,
 * semantic_context_search, and retrieve_agent_memory, deduplicates by
 * content hash, attaches provenance, and ranks by a composite score.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieval-harness front-end tool.
 */
class WP_MCP_AI_Tool_Retrieve_With_Provenance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'retrieve_with_provenance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Retrieve With Provenance', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Unified retrieval facade. Queries recall_memory, semantic_context_search, and retrieve_agent_memory in one call, deduplicates results by content hash, and returns top-k passages with citation metadata, freshness scores, and a recall-confidence aggregate.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'query'         => array(
					'type'        => 'string',
					'description' => 'The retrieval query.',
				),
				'k'             => array(
					'type'        => 'integer',
					'description' => 'Number of passages to return (1-50). Defaults to 5.',
					'minimum'     => 1,
					'maximum'     => 50,
				),
				'wing'          => array(
					'type'        => 'string',
					'description' => 'Optional MemPalace wing (project / client / matter / patient / deal).',
				),
				'room'          => array(
					'type'        => 'string',
					'description' => 'Optional MemPalace room.',
				),
				'assistant_id'  => array(
					'type'        => 'integer',
					'description' => 'Optional assistant post ID to scope retrieval.',
				),
				'task_class'    => array(
					'type'        => 'string',
					'description' => 'Optional task class hint.',
				),
				'verify_answer' => array(
					'type'        => 'string',
					'description' => 'Optional. If provided, the harness also runs citation verification on this candidate answer and returns coverage stats.',
				),
			),
			'required'   => array( 'query' ),
		);
	}

	/**
	 * Get the required capability for this tool.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the retrieve with provenance tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$query = isset( $arguments['query'] ) ? trim( (string) $arguments['query'] ) : '';
		if ( '' === $query ) {
			return new WP_Error( 'wp_mcp_ai_retrieve_with_provenance_empty_query', __( 'A non-empty query is required.', 'mcp-ai-wpoos' ) );
		}

		$k = isset( $arguments['k'] ) ? (int) $arguments['k'] : 5;

		$scope = array(
			'wing'         => isset( $arguments['wing'] ) ? sanitize_text_field( (string) $arguments['wing'] ) : '',
			'room'         => isset( $arguments['room'] ) ? sanitize_text_field( (string) $arguments['room'] ) : '',
			'assistant_id' => isset( $arguments['assistant_id'] ) ? (int) $arguments['assistant_id'] : 0,
			'task_class'   => isset( $arguments['task_class'] ) ? sanitize_key( (string) $arguments['task_class'] ) : '',
		);

		$result = WP_MCP_AI_Retrieval_Harness::retrieve( $query, $scope, $k, $context );

		if ( isset( $arguments['verify_answer'] ) && '' !== trim( (string) $arguments['verify_answer'] ) ) {
			$verification           = WP_MCP_AI_Retrieval_Harness::verify_citations( (string) $arguments['verify_answer'], $result['passages'] );
			$result['verification'] = $verification;
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'cacheable' );
	}
}
