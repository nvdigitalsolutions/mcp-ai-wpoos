<?php
/**
 * Self-Consistency Vote tool (D8 Cluster 3 port of the base plugin's
 * WP_MCP_AI_Tool_Self_Consistency_Vote — byte-identical slug, schema,
 * error codes, and envelope; serves the platform-ported ReasoningTrace).
 *
 * @package NvoosContentGraphAiPlatform\Harness\Tools
 * @since   2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness\Tools;

use NvoosContentGraphAi\Tools\AbstractAiTool;
use NvoosContentGraphAiPlatform\Harness\ReasoningTrace;

/**
 * Majority-vote across candidate answers.
 */
class SelfConsistencyVoteTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'self_consistency_vote';
	}

	public function getName(): string {
		return __( 'Self-Consistency Vote', 'nvoos-content-graph-ai-platform' );
	}

	public function getDescription(): string {
		return __( 'Pick the modal answer across N candidate solutions and report an agreement ratio. Use after sampling the same prompt multiple times to estimate confidence in the final answer.', 'nvoos-content-graph-ai-platform' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'candidates' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'List of candidate answers to vote across.',
				),
			),
			'required'   => array( 'candidates' ),
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'local-only', 'idempotent' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$candidates = array();
		if ( isset( $arguments['candidates'] ) && is_array( $arguments['candidates'] ) ) {
			foreach ( $arguments['candidates'] as $cand ) {
				if ( is_string( $cand ) ) {
					$candidates[] = $cand;
				} elseif ( is_scalar( $cand ) ) {
					$candidates[] = (string) $cand;
				}
			}
		}

		if ( empty( $candidates ) ) {
			return new \WP_Error( 'wp_mcp_ai_self_consistency_no_candidates', __( 'At least one candidate is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Hard cap to prevent ridiculous payloads.
		if ( count( $candidates ) > 64 ) {
			$candidates = array_slice( $candidates, 0, 64 );
		}

		$result = ReasoningTrace::self_consistency_vote( $candidates );
		return array(
			'success' => true,
			'result'  => $result,
		);
	}
}
