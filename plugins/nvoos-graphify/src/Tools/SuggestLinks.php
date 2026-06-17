<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

use NvoosGraphify\Graph\Analyzer;
use NvoosGraphify\Graph\Db;

use function absint;
use function __;
use function _n;
use function sprintf;

/**
 * Tool: nvoos_graphify_suggest_links
 *
 * Internal link suggestions based on knowledge graph communities.
 *
 * @since 1.0.0
 */
class SuggestLinks extends AbstractTool {

	public function getSlug(): string {
		return 'nvoos_graphify_suggest_links';
	}

	public function getName(): string {
		return __( 'Suggest Internal Links', 'nvoos-graphify' );
	}

	public function getDescription(): string {
		return __( 'Identify missing internal links between content pieces that are in the same knowledge community. Returns ranked suggestions of post pairs that share a topic cluster but are not yet directly linked — actionable SEO and UX improvements.', 'nvoos-graphify' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum link suggestions to return (default: 10, max: 50).', 'nvoos-graphify' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'Focus suggestions on a specific post ID (optional).', 'nvoos-graphify' ),
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $context   Execution context.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$limit   = isset( $arguments['limit'] ) ? max( 1, min( 50, absint( $arguments['limit'] ) ) ) : 10;
		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;

		if ( $post_id ) {
			$node = Db::getNodeByPostId( $post_id );
			if ( ! $node || ! $node->community_id ) {
				return array(
					'success'          => true,
					'suggestions'      => array(),
					'suggestion_count' => 0,
					'message'          => __( 'No community data available for this post. Run graphify_build_graph first.', 'nvoos-graphify' ),
				);
			}
		}

		$suggestions = Analyzer::getRecommendations( $limit );

		// Filter to specific post if provided.
		if ( $post_id && $node ) {
			$suggestions = array_filter(
				$suggestions,
				function ( $s ) use ( $node ) {
					return isset( $s['source_node'] ) && (
						$s['source_node'] === $node->node_id || $s['target_node'] === $node->node_id
					);
				}
			);
			$suggestions = array_values( $suggestions );
		}

		return array(
			'success'          => true,
			'suggestions'      => $suggestions,
			'suggestion_count' => count( $suggestions ),
			'summary'          => sprintf(
				/* translators: %d: suggestion count */
				_n(
					'Found %d internal link opportunity.',
					'Found %d internal link opportunities.',
					count( $suggestions ),
					'nvoos-graphify'
				),
				count( $suggestions )
			),
		);
	}
}
