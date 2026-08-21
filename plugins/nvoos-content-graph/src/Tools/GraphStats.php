<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tools;

use function __;

/**
 * Tool: nvoos_content_graph_graph_stats
 *
 * Returns aggregate statistics about the knowledge graph.
 *
 * @since 1.0.0
 */
class GraphStats extends AbstractTool {

	/**
	 * {@inheritdoc}
	 */
	public function getSlug(): string {
		return 'nvoos_content_graph_graph_stats';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return __( 'Knowledge Graph Statistics', 'nvoos-content-graph' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDescription(): string {
		return __( 'Return aggregate statistics about the site knowledge graph: total node and edge counts, breakdown by type, edge confidence distribution, number of communities, and last build timestamp. Use this to understand how well the knowledge graph has been built before running deeper analysis tools.', 'nvoos-content-graph' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCapabilityFlags(): array {
		return array( 'read-only', 'cacheable' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $context   Execution context.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$stats        = \NvoosContentGraph\Graph\Db::getStats();
		$last_build   = \NvoosContentGraph\Graph\Db::getMeta( 'last_build_completed', 'never' );
		$build_status = \NvoosContentGraph\Graph\Db::getMeta( 'build_status', 'idle' );

		return array(
			'success'      => true,
			'stats'        => $stats,
			'last_build'   => $last_build,
			'build_status' => $build_status,
		);
	}
}
