<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tools;

use NvoosContentGraph\Graph\Analyzer;

use function __;
use function _n;
use function sprintf;

/**
 * Tool: nvoos_content_graph_content_gaps
 *
 * Identifies thin communities, orphan nodes, and content creation suggestions.
 *
 * @since 1.0.0
 */
class ContentGaps extends AbstractTool {

	public function getSlug(): string {
		return 'nvoos_content_graph_content_gaps';
	}

	public function getName(): string {
		return __( 'Content Gaps Analysis', 'nvoos-content-graph' );
	}

	public function getDescription(): string {
		return __( 'Identify knowledge gaps in the site content: orphan nodes (no connections), thin communities (under-developed topic clusters), high ambiguity in AI-extracted relationships, and hubless communities that lack a strong central piece. Returns actionable content creation suggestions.', 'nvoos-content-graph' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
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
		$gaps        = Analyzer::getKnowledgeGaps();
		$suggestions = Analyzer::getRecommendations( 10 );
		$surprising  = Analyzer::getSurprisingConnections( 5 );

		$summary_parts = array();
		$orphan_count  = count( $gaps['orphans'] );
		$thin_count    = count( $gaps['thin_communities'] );

		if ( $orphan_count > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: orphan count */
				_n( '%d isolated node', '%d isolated nodes', $orphan_count, 'nvoos-content-graph' ),
				$orphan_count
			);
		}
		if ( $thin_count > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: thin community count */
				_n( '%d under-developed community', '%d under-developed communities', $thin_count, 'nvoos-content-graph' ),
				$thin_count
			);
		}
		if ( $gaps['ambiguity_rate'] > 0.1 ) {
			$summary_parts[] = sprintf(
				/* translators: %s: ambiguity percentage */
				__( '%s ambiguous relationships', 'nvoos-content-graph' ),
				round( $gaps['ambiguity_rate'] * 100, 1 ) . '%'
			);
		}

		$summary = empty( $summary_parts )
			? __( 'No significant knowledge gaps found. Graph is well-connected.', 'nvoos-content-graph' )
			: implode( ', ', $summary_parts ) . '.';

		return array(
			'success'         => true,
			'gaps'            => $gaps,
			'recommendations' => $suggestions,
			'surprising'      => $surprising,
			'summary'         => $summary,
		);
	}
}
