<?php
/**
 * Artifact Lineage — ancestry/descendant graphs for evolved artifacts.
 *
 * Phase G of the artifact-evolution plan. Walks the Phase C population's
 * `parent`/`children` links into a deterministic graph payload (nodes +
 * edges), resolves the seed ancestor, and renders an ASCII tree for
 * admin/CLI surfaces. Depth limits keep walks bounded.
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact Lineage class.
 *
 * @since 1.9.0
 */
class ArtifactLineage {

	/**
	 * Build a lineage graph around one population entry.
	 *
	 * The graph contains the entry, all of its ancestors up to the seed,
	 * and all descendants (each branch bounded by `$max_depth` when > 0).
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type.
	 * @param string $hash          Population hash of the entry.
	 * @param int    $max_depth     Optional depth bound (0 = unbounded).
	 * @return array{nodes:array<string,array>,edges:array<int,array<int,string>>,root:string}
	 */
	public static function graph( $artifact_type, $hash, $max_depth = 0 ) {
		$artifact_type = sanitize_key( (string) $artifact_type );
		$hash          = sanitize_key( (string) $hash );
		$max_depth     = max( 0, (int) $max_depth );

		$nodes = array();
		$edges = array();

		if ( ! class_exists( __NAMESPACE__ . '\\ArtifactPopulation' ) ) {
			return array(
				'nodes' => $nodes,
				'edges' => $edges,
				'root'  => '',
			);
		}

		$population = ArtifactPopulation::get_population();
		$entry      = isset( $population[ $hash ] ) ? $population[ $hash ] : null;

		if ( null === $entry ) {
			return array(
				'nodes' => $nodes,
				'edges' => $edges,
				'root'  => '',
			);
		}

		$nodes[ $hash ] = self::node_payload( $entry );

		// Ancestors (parent chain up to the seed).
		$root    = $hash;
		$current = $entry;
		$hops    = 0;
		while ( ! empty( $current['parent'] ) && isset( $population[ $current['parent'] ] ) ) {
			$parent                   = $population[ $current['parent'] ];
			$nodes[ $parent['hash'] ] = self::node_payload( $parent );
			$edges[]                  = array( $parent['hash'], $current['hash'] );
			$root                     = $parent['hash'];
			$current                  = $parent;

			++$hops;
			if ( $hops > count( $population ) ) {
				break; // Cycle guard.
			}
		}

		// Descendants (bounded DFS, deterministic child order).
		self::walk_descendants( $hash, $population, 1, $max_depth, $nodes, $edges );

		return array(
			'nodes' => $nodes,
			'edges' => self::sort_edges( $edges ),
			'root'  => $root,
		);
	}

	/**
	 * Content-address a payload exactly like the population archive.
	 *
	 * Prompt strings are wrapped in `array( 'prompt' => … )` to match the
	 * evolver's canonical archive shape, so a deployed prompt resolves to
	 * its population entry. Other types address as-is.
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type.
	 * @param mixed  $payload       Artifact payload.
	 * @return string MD5 hash.
	 */
	public static function hash_for( $artifact_type, $payload ) {
		$artifact_type = sanitize_key( (string) $artifact_type );

		if ( 'prompt' === $artifact_type && is_string( $payload ) ) {
			$payload = array( 'prompt' => $payload );
		}

		return md5(
			wp_json_encode(
				array(
					'artifact_type' => $artifact_type,
					'artifact'      => $payload,
				)
			)
		);
	}

	/**
	 * Canonically sort edges (deterministic output for consumers/tests).
	 *
	 * @since 1.9.0
	 *
	 * @param array<int,array<int,string>> $edges Edge list.
	 * @return array<int,array<int,string>> Sorted edge list.
	 */
	private static function sort_edges( array $edges ) {
		usort(
			$edges,
			static function ( $a, $b ) {
				$cmp = strcmp( $a[0], $b[0] );
				return 0 !== $cmp ? $cmp : strcmp( $a[1], $b[1] );
			}
		);

		return $edges;
	}

	/**
	 * Resolve the seed ancestor of an entry.
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type.
	 * @param string $hash          Population hash of the entry.
	 * @return string|null Root hash, or null when the entry is unknown.
	 */
	public static function get_root( $artifact_type, $hash ) {
		$graph = self::graph( $artifact_type, $hash );

		return '' !== $graph['root'] ? $graph['root'] : null;
	}

	/**
	 * Render an ASCII lineage tree for admin/CLI surfaces.
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type.
	 * @param string $hash          Population hash of the entry.
	 * @param int    $max_depth     Optional depth bound (0 = unbounded).
	 * @return string Multi-line tree, or empty string when the entry is unknown.
	 */
	public static function render_ascii( $artifact_type, $hash, $max_depth = 0 ) {
		$graph = self::graph( $artifact_type, $hash, $max_depth );

		if ( empty( $graph['nodes'] ) ) {
			return '';
		}

		$root = $graph['root'];
		if ( ! isset( $graph['nodes'][ $root ] ) ) {
			return '';
		}

		$lines   = array();
		$lines[] = self::ascii_label( $graph['nodes'][ $root ] );

		$children = self::sorted_children( $root, $graph );
		$count    = count( $children );
		foreach ( $children as $index => $child ) {
			$is_last = ( $index === $count - 1 );
			self::render_ascii_branch( $child, $graph, $max_depth, 1, $is_last, '', $lines );
		}

		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a node payload from a population entry.
	 *
	 * @since 1.9.0
	 *
	 * @param array $entry Population entry.
	 * @return array Node payload.
	 */
	private static function node_payload( array $entry ) {
		return array(
			'hash'           => isset( $entry['hash'] ) ? (string) $entry['hash'] : '',
			'artifact_type'  => isset( $entry['artifact_type'] ) ? (string) $entry['artifact_type'] : '',
			'artifact_id'    => isset( $entry['artifact_id'] ) ? (string) $entry['artifact_id'] : '',
			'score'          => isset( $entry['score'] ) ? (float) $entry['score'] : 0.0,
			'parent'         => isset( $entry['parent'] ) ? (string) $entry['parent'] : '',
			'children_count' => isset( $entry['children_count'] ) ? (int) $entry['children_count'] : 0,
			'created_at'     => isset( $entry['created_at'] ) ? (int) $entry['created_at'] : 0,
			'sources'        => isset( $entry['sources'] ) && is_array( $entry['sources'] ) ? $entry['sources'] : array(),
		);
	}

	/**
	 * Bounded DFS over descendants, appending nodes and parent→child edges.
	 *
	 * @since 1.9.0
	 *
	 * @param string $hash       Parent hash.
	 * @param array  $population Population keyed by hash.
	 * @param int    $depth      Current depth (1 = direct child).
	 * @param int    $max_depth  Depth bound (0 = unbounded).
	 * @param array  $nodes      Node map (by reference).
	 * @param array  $edges      Edge list (by reference).
	 * @return void
	 */
	private static function walk_descendants( $hash, array $population, $depth, $max_depth, &$nodes, &$edges ) {
		if ( $max_depth > 0 && $depth > $max_depth ) {
			return;
		}

		$parent = isset( $population[ $hash ] ) ? $population[ $hash ] : null;
		if ( null === $parent ) {
			return;
		}

		$children = isset( $parent['children'] ) && is_array( $parent['children'] ) ? $parent['children'] : array();
		sort( $children );

		foreach ( $children as $child_hash ) {
			$child = isset( $population[ $child_hash ] ) ? $population[ $child_hash ] : null;
			if ( null === $child ) {
				continue;
			}

			$nodes[ $child_hash ] = self::node_payload( $child );
			$edges[]              = array( $hash, $child_hash );

			self::walk_descendants( $child_hash, $population, $depth + 1, $max_depth, $nodes, $edges );
		}
	}

	/**
	 * Deterministically sorted children of a node within a graph.
	 *
	 * @since 1.9.0
	 *
	 * @param string $hash  Parent hash.
	 * @param array  $graph Graph payload.
	 * @return array<int,string> Child hashes.
	 */
	private static function sorted_children( $hash, array $graph ) {
		$children = array();

		foreach ( $graph['edges'] as $edge ) {
			if ( $edge[0] === $hash ) {
				$children[] = $edge[1];
			}
		}

		$children = array_values( array_unique( $children ) );
		sort( $children );

		return $children;
	}

	/**
	 * Human-readable node label for the ASCII tree.
	 *
	 * @since 1.9.0
	 *
	 * @param array $node Node payload.
	 * @return string Label.
	 */
	private static function ascii_label( array $node ) {
		return sprintf(
			'%s (%s, score %s)%s',
			substr( $node['hash'], 0, 8 ),
			$node['artifact_type'],
			number_format( (float) $node['score'], 2, '.', '' ),
			$node['children_count'] > 0 ? ' [' . $node['children_count'] . ' children]' : ''
		);
	}

	/**
	 * Recursive ASCII branch renderer.
	 *
	 * @since 1.9.0
	 *
	 * @param string $hash      Node hash.
	 * @param array  $graph     Graph payload.
	 * @param int    $max_depth Depth bound.
	 * @param int    $depth     Current depth.
	 * @param bool   $is_last   Whether this node is the last sibling.
	 * @param string $prefix    Prefix string for this branch.
	 * @param array  $lines     Output lines (by reference).
	 * @return void
	 */
	private static function render_ascii_branch( $hash, array $graph, $max_depth, $depth, $is_last, $prefix, &$lines ) {
		if ( $max_depth > 0 && $depth > $max_depth ) {
			return;
		}

		if ( ! isset( $graph['nodes'][ $hash ] ) ) {
			return;
		}

		$connector = $is_last ? '└── ' : '├── ';
		$lines[]   = $prefix . $connector . self::ascii_label( $graph['nodes'][ $hash ] );

		$children     = self::sorted_children( $hash, $graph );
		$count        = count( $children );
		$child_prefix = $prefix . ( $is_last ? '    ' : '│   ' );

		foreach ( $children as $index => $child ) {
			self::render_ascii_branch( $child, $graph, $max_depth, $depth + 1, $index === $count - 1, $child_prefix, $lines );
		}
	}
}
