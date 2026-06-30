<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

use function absint;
use function sanitize_text_field;
use function __;

/**
 * Tool: nvoos_graphify_get_neighbors
 *
 * Returns all directly connected nodes for a given graph node.
 *
 * @since 1.0.0
 */
class GetNeighbors extends AbstractTool {

	/**
	 * {@inheritdoc}
	 */
	public function getSlug(): string {
		return 'nvoos_graphify_get_neighbors';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return __( 'Get Node Neighbors', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDescription(): string {
		return __( 'Return all nodes directly connected to a given node in the knowledge graph. Optionally filter by relationship type (e.g. LINKS_TO, CATEGORIZED_BY, discusses_topic). Results include each neighbor\'s label, type, URL, degree, and the connecting relation.', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'node_id'       => array(
					'type'        => 'string',
					'description' => __( 'Node identifier (e.g. "post_123"). Use graphify_get_node to look up a node_id.', 'nvoos-graphify' ),
				),
				'label'         => array(
					'type'        => 'string',
					'description' => __( 'Node label (alternative to node_id, uses fuzzy search).', 'nvoos-graphify' ),
					'maxLength'   => 255,
				),
				'relation'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by relation type, e.g. "LINKS_TO", "CATEGORIZED_BY", "discusses_topic". Leave empty for all relations.', 'nvoos-graphify' ),
					'maxLength'   => 128,
				),
				'max_neighbors' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of neighbor edges to return (default: 100, max: 500).', 'nvoos-graphify' ),
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 100,
				),
			),
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
		$node = null;
		if ( ! empty( $arguments['node_id'] ) ) {
			$node = \NvoosGraphify\Graph\Db::getNode( sanitize_text_field( $arguments['node_id'] ) );
		} elseif ( ! empty( $arguments['label'] ) ) {
			$results = \NvoosGraphify\Graph\Db::searchNodes( sanitize_text_field( $arguments['label'] ), '', 1 );
			$node    = ! empty( $results ) ? $results[0] : null;
		}

		if ( ! $node ) {
			return new \WP_Error(
				'get_neighbors_node_not_found',
				__( 'Node not found.', 'nvoos-graphify' )
			);
		}

		$relation      = isset( $arguments['relation'] ) ? sanitize_text_field( $arguments['relation'] ) : '';
		$max_neighbors = isset( $arguments['max_neighbors'] ) ? max( 1, min( 500, absint( $arguments['max_neighbors'] ) ) ) : 100;
		$edges         = \NvoosGraphify\Graph\Db::getEdgesForNode( $node->node_id, $relation, $max_neighbors );

		$neighbors = array();
		foreach ( $edges as $edge ) {
			$nid         = ( $edge->source_node_id === $node->node_id ) ? $edge->target_node_id : $edge->source_node_id;
			$nbr_node    = \NvoosGraphify\Graph\Db::getNode( $nid );
			$neighbors[] = array(
				'node_id'    => $nid,
				'label'      => $nbr_node ? $nbr_node->label : $nid,
				'type'       => $nbr_node ? $nbr_node->type : '',
				'url'        => $nbr_node ? $nbr_node->url : '',
				'degree'     => $nbr_node ? (int) $nbr_node->degree : 0,
				'relation'   => $edge->relation,
				'confidence' => floatval( $edge->confidence ),
				'provenance' => $edge->provenance,
				'direction'  => ( $edge->source_node_id === $node->node_id ) ? 'outgoing' : 'incoming',
			);
		}

		return array(
			'success'         => true,
			'node'            => array(
				'node_id' => $node->node_id,
				'label'   => $node->label,
				'type'    => $node->type,
			),
			'relation_filter' => $relation,
			'neighbor_count'  => count( $neighbors ),
			'neighbors'       => $neighbors,
		);
	}
}
