<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

use function absint;
use function sanitize_text_field;
use function __;

/**
 * Tool: nvoos_graphify_get_node
 *
 * Look up full node details by label search or post ID.
 *
 * @since 1.0.0
 */
class GetNode extends AbstractTool {

	/**
	 * {@inheritdoc}
	 */
	public function getSlug(): string {
		return 'nvoos_graphify_get_node';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return __( 'Get Graph Node', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDescription(): string {
		return __( 'Retrieve full details for a single knowledge graph node including its metadata, properties, degree count, community assignment, and direct neighbor edges. Lookup by label (fuzzy search) or by WordPress post ID.', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'label'   => array(
					'type'        => 'string',
					'description' => __( 'Node label to search for (case-insensitive, partial match). Use this or post_id.', 'nvoos-graphify' ),
					'maxLength'   => 255,
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID. Use this or label.', 'nvoos-graphify' ),
					'minimum'     => 1,
				),
				'node_id' => array(
					'type'        => 'string',
					'description' => __( 'Exact node identifier (e.g. "post_123"). Use this for precise lookup.', 'nvoos-graphify' ),
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
		} elseif ( ! empty( $arguments['post_id'] ) ) {
			$node = \NvoosGraphify\Graph\Db::getNodeByPostId( absint( $arguments['post_id'] ) );
		} elseif ( ! empty( $arguments['label'] ) ) {
			$results = \NvoosGraphify\Graph\Db::searchNodes( sanitize_text_field( $arguments['label'] ), '', 1 );
			$node    = ! empty( $results ) ? $results[0] : null;
		}

		if ( ! $node ) {
			return new \WP_Error(
				'get_node_not_found',
				__( 'Node not found. Build the graph first with graphify_build_graph.', 'nvoos-graphify' )
			);
		}

		$edges     = \NvoosGraphify\Graph\Db::getEdgesForNode( $node->node_id );
		$neighbors = array();
		foreach ( $edges as $edge ) {
			$nid         = ( $edge->source_node_id === $node->node_id ) ? $edge->target_node_id : $edge->source_node_id;
			$nbr_node    = \NvoosGraphify\Graph\Db::getNode( $nid );
			$neighbors[] = array(
				'node_id'   => $nid,
				'label'     => $nbr_node ? $nbr_node->label : $nid,
				'type'      => $nbr_node ? $nbr_node->type : '',
				'relation'  => $edge->relation,
				'direction' => ( $edge->source_node_id === $node->node_id ) ? 'outgoing' : 'incoming',
			);
		}

		return array(
			'success'    => true,
			'node'       => $node,
			'neighbors'  => $neighbors,
			'edge_count' => count( $edges ),
		);
	}
}
