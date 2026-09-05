<?php
/**
 * Retrieve With Provenance tool (D8 Cluster 3 port of the base plugin's
 * WP_MCP_AI_Tool_Retrieve_With_Provenance — byte-identical slug, schema,
 * error codes, and envelope; serves the platform-ported
 * RetrievalHarness).
 *
 * @package NvoosContentGraphAiPlatform\Harness\Tools
 * @since   2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness\Tools;

use NvoosContentGraphAi\Tools\AbstractAiTool;
use NvoosContentGraphAiPlatform\Harness\RetrievalHarness;

/**
 * Unified retrieval facade with citation provenance.
 */
class RetrieveWithProvenanceTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'retrieve_with_provenance';
	}

	public function getName(): string {
		return __( 'Retrieve With Provenance', 'nvoos-content-graph-ai-platform' );
	}

	public function getDescription(): string {
		return __( 'Unified retrieval facade. Queries recall_memory, semantic_context_search, and retrieve_agent_memory in one call, deduplicates results by content hash, and returns top-k passages with citation metadata, freshness scores, and a recall-confidence aggregate.', 'nvoos-content-graph-ai-platform' );
	}

	public function getParametersSchema(): array {
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

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'cacheable' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$query = isset( $arguments['query'] ) ? trim( (string) $arguments['query'] ) : '';
		if ( '' === $query ) {
			return new \WP_Error( 'wp_mcp_ai_retrieve_with_provenance_empty_query', __( 'A non-empty query is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$k = isset( $arguments['k'] ) ? (int) $arguments['k'] : 5;

		$scope = array(
			'wing'         => isset( $arguments['wing'] ) ? sanitize_text_field( (string) $arguments['wing'] ) : '',
			'room'         => isset( $arguments['room'] ) ? sanitize_text_field( (string) $arguments['room'] ) : '',
			'assistant_id' => isset( $arguments['assistant_id'] ) ? (int) $arguments['assistant_id'] : 0,
			'task_class'   => isset( $arguments['task_class'] ) ? sanitize_key( (string) $arguments['task_class'] ) : '',
		);

		$result = RetrievalHarness::retrieve( $query, $scope, $k, $context );

		if ( isset( $arguments['verify_answer'] ) && '' !== trim( (string) $arguments['verify_answer'] ) ) {
			$verification           = RetrievalHarness::verify_citations( (string) $arguments['verify_answer'], $result['passages'] );
			$result['verification'] = $verification;
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}
}
