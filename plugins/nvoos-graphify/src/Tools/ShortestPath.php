<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

use NvoosGraphify\Graph\Analyzer;
use NvoosGraphify\Graph\Db;

use function absint;
use function __;
use function sanitize_text_field;

/**
 * Tool: nvoos_graphify_shortest_path
 *
 * Find the shortest content path between two topics/posts using BFS.
 *
 * @since 1.0.0
 */
class ShortestPath extends AbstractTool {

	public function getSlug(): string {
		return 'nvoos_graphify_shortest_path';
	}

	public function getName(): string {
		return __( 'Shortest Path Between Topics', 'nvoos-graphify' );
	}

	public function getDescription(): string {
		return __( 'Find the shortest content path between two topics or posts in the knowledge graph using BFS. Returns the sequence of nodes connecting them, which reveals surprising semantic bridges. Provide start and end as labels, node_ids, or post IDs.', 'nvoos-graphify' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'start'     => array(
					'type'        => 'string',
					'description' => __( 'Label or node_id of the starting node.', 'nvoos-graphify' ),
					'minLength'   => 1,
					'maxLength'   => 255,
				),
				'end'       => array(
					'type'        => 'string',
					'description' => __( 'Label or node_id of the destination node.', 'nvoos-graphify' ),
					'minLength'   => 1,
					'maxLength'   => 255,
				),
				'max_depth' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum path length to search (default: 6).', 'nvoos-graphify' ),
					'minimum'     => 2,
					'maximum'     => 10,
					'default'     => 6,
				),
			),
			'required'             => array( 'start', 'end' ),
			'additionalProperties' => false,
		);
	}

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
		$start_label = sanitize_text_field( $arguments['start'] ?? '' );
		$end_label   = sanitize_text_field( $arguments['end'] ?? '' );
		$max_depth   = isset( $arguments['max_depth'] ) ? max( 2, min( 10, absint( $arguments['max_depth'] ) ) ) : 6;

		if ( ! $start_label || ! $end_label ) {
			return array(
				'success' => false,
				'error'   => __( 'Both start and end are required.', 'nvoos-graphify' ),
			);
		}

		// Resolve start node.
		$start_node = Db::getNode( $start_label );
		if ( ! $start_node ) {
			$results    = Db::searchNodes( $start_label, '', 1 );
			$start_node = ! empty( $results ) ? $results[0] : null;
		}

		// Resolve end node.
		$end_node = Db::getNode( $end_label );
		if ( ! $end_node ) {
			$results  = Db::searchNodes( $end_label, '', 1 );
			$end_node = ! empty( $results ) ? $results[0] : null;
		}

		if ( ! $start_node ) {
			return array(
				'success' => false,
				'error'   => sprintf( __( 'Start node "%s" not found.', 'nvoos-graphify' ), $start_label ),
			);
		}
		if ( ! $end_node ) {
			return array(
				'success' => false,
				'error'   => sprintf( __( 'End node "%s" not found.', 'nvoos-graphify' ), $end_label ),
			);
		}

		$path_ids = Analyzer::shortestPath( $start_node->node_id, $end_node->node_id, $max_depth );

		if ( null === $path_ids ) {
			return array(
				'success' => false,
				'start'   => $start_node->label,
				'end'     => $end_node->label,
				'error'   => sprintf(
					__( 'No path found between "%1$s" and "%2$s" within depth %3$d.', 'nvoos-graphify' ),
					$start_node->label,
					$end_node->label,
					$max_depth
				),
			);
		}

		// Expand path IDs to full node details.
		$path_nodes = array();
		foreach ( $path_ids as $nid ) {
			$n            = Db::getNode( $nid );
			$path_nodes[] = $n ? array(
				'node_id' => $n->node_id,
				'label'   => $n->label,
				'type'    => $n->type,
				'url'     => $n->url,
			) : array( 'node_id' => $nid );
		}

		return array(
			'success'     => true,
			'start'       => $start_node->label,
			'end'         => $end_node->label,
			'path_length' => count( $path_ids ) - 1,
			'path'        => $path_nodes,
			'summary'     => sprintf(
				/* translators: 1: start label, 2: end label, 3: path length */
				__( 'Shortest path from "%1$s" to "%2$s" is %3$d hop(s).', 'nvoos-graphify' ),
				$start_node->label,
				$end_node->label,
				count( $path_ids ) - 1
			),
		);
	}
}
