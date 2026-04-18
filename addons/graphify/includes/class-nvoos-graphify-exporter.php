<?php
/**
 * NV oOS Graphify — Exporter
 *
 * Exports the knowledge graph in six formats:
 *   JSON     — NetworkX-compatible node-link format
 *   HTML     — standalone interactive Cytoscape.js page (CDN, dark theme)
 *   GraphML  — for Gephi / yEd import
 *   CSV      — separate nodes.csv + edges.csv (returned as a zip or two strings)
 *   Neo4j    — CREATE Cypher statements
 *   Obsidian — Markdown files per community with wikilinks
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-format knowledge graph exporter.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Exporter {

	/**
	 * Export the graph in the requested format.
	 *
	 * @since 0.5.0
	 *
	 * @param string $format   One of: json, html, graphml, csv, neo4j, obsidian.
	 * @param array  $args     Optional arguments (e.g. max_nodes, community_id).
	 * @return string|array    String for most formats; array of strings for CSV.
	 */
	public static function export( $format, array $args = array() ) {
		$max_nodes = isset( $args['max_nodes'] ) ? max( 1, min( 5000, absint( $args['max_nodes'] ) ) ) : 2000;

		$nodes = NV_oOS_Graphify_DB::list_nodes(
			array(
				'limit'       => $max_nodes,
				'order_by'    => 'degree',
				'order'       => 'DESC',
				'community_id'=> isset( $args['community_id'] ) ? sanitize_text_field( $args['community_id'] ) : '',
			)
		);

		$node_ids  = wp_list_pluck( $nodes, 'node_id' );
		$node_set  = array_flip( $node_ids );
		$all_edges = self::edges_for_nodes( $node_ids );

		switch ( sanitize_key( $format ) ) {
			case 'html':
				return self::to_html( $nodes, $all_edges );
			case 'graphml':
				return self::to_graphml( $nodes, $all_edges );
			case 'csv':
				return self::to_csv( $nodes, $all_edges );
			case 'neo4j':
				return self::to_neo4j( $nodes, $all_edges );
			case 'obsidian':
				return self::to_obsidian( $nodes, $all_edges );
			case 'json':
			default:
				return self::to_json( $nodes, $all_edges );
		}
	}

	// -------------------------------------------------------------------------
	// JSON — NetworkX node-link format
	// -------------------------------------------------------------------------

	/**
	 * Export as NetworkX-compatible JSON.
	 *
	 * @since 0.5.0
	 *
	 * @param object[] $nodes Node rows.
	 * @param object[] $edges Edge rows.
	 * @return string JSON string.
	 */
	private static function to_json( array $nodes, array $edges ) {
		$node_data = array();
		foreach ( $nodes as $n ) {
			$node_data[] = array(
				'id'           => $n->node_id,
				'label'        => $n->label,
				'type'         => $n->type,
				'post_id'      => (int) $n->post_id,
				'url'          => $n->url,
				'degree'       => (int) $n->degree,
				'community_id' => $n->community_id,
				'properties'   => json_decode( $n->properties, true ),
			);
		}

		$link_data = array();
		foreach ( $edges as $e ) {
			$link_data[] = array(
				'source'     => $e->source_node_id,
				'target'     => $e->target_node_id,
				'relation'   => $e->relation,
				'confidence' => floatval( $e->confidence ),
				'provenance' => $e->provenance,
			);
		}

		return wp_json_encode(
			array(
				'directed'   => true,
				'multigraph' => false,
				'graph'      => array( 'generated_at' => gmdate( 'c' ) ),
				'nodes'      => $node_data,
				'links'      => $link_data,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
		);
	}

	// -------------------------------------------------------------------------
	// HTML — Standalone Cytoscape.js page
	// -------------------------------------------------------------------------

	/**
	 * Export as a standalone interactive HTML page.
	 *
	 * Uses Cytoscape.js and fcose layout from CDN (no bundled vendor files).
	 *
	 * @since 0.5.0
	 *
	 * @param object[] $nodes Node rows.
	 * @param object[] $edges Edge rows.
	 * @return string HTML string.
	 */
	private static function to_html( array $nodes, array $edges ) {
		$cy_elements = self::build_cytoscape_elements( $nodes, $edges );
		$cy_json     = wp_json_encode( $cy_elements );
		$title       = esc_html__( 'Knowledge Graph Export', 'nvoos-graphify' );

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone export file, not a WP page.
		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.28.1/cytoscape.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape-fcose/2.2.0/cytoscape-fcose.min.js"></script>
<style>
  body { margin: 0; background: #0f0f1a; color: #e0e0ff; font-family: sans-serif; }
  #cy { width: 100vw; height: 100vh; }
  #search { position: fixed; top: 12px; left: 12px; z-index: 10; }
  #search input { padding: 6px 10px; border-radius: 4px; border: 1px solid #444; background: #1a1a2e; color: #e0e0ff; }
  #info { position: fixed; top: 12px; right: 12px; z-index: 10; background: #1a1a2e; padding: 12px; border-radius: 6px; max-width: 280px; display: none; }
  #info h3 { margin: 0 0 6px; font-size: 14px; }
  #info p  { margin: 0; font-size: 12px; opacity: 0.8; }
  legend { position: fixed; bottom: 12px; left: 12px; background: #1a1a2e; padding: 10px; border-radius: 6px; font-size: 12px; }
  legend span { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 4px; }
</style>
</head>
<body>
<div id="search"><input id="q" type="text" placeholder="Search nodes…"></div>
<div id="info"></div>
<div id="cy"></div>
<legend id="legend"></legend>
<script>
const elements = {$cy_json};
const cy = cytoscape({
  container: document.getElementById('cy'),
  elements,
  style: [
    { selector: 'node', style: { 'label': 'data(label)', 'font-size': 10, 'color': '#e0e0ff', 'background-color': 'data(color)', 'width': 'mapData(degree,0,50,10,60)', 'height': 'mapData(degree,0,50,10,60)' } },
    { selector: 'edge', style: { 'width': 1, 'line-color': '#444', 'opacity': 0.6, 'curve-style': 'bezier' } },
    { selector: ':selected', style: { 'border-width': 2, 'border-color': '#fff' } }
  ],
  layout: { name: 'fcose', animate: true }
});
cy.on('tap', 'node', function(e){ const n=e.target.data(); document.getElementById('info').style.display='block'; document.getElementById('info').innerHTML='<h3>'+n.label+'</h3><p>Type: '+n.type+'</p><p>Degree: '+n.degree+'</p>'+(n.url?'<p><a href="'+n.url+'" target="_blank" style="color:#8af">Open ↗</a></p>':''); });
document.getElementById('q').addEventListener('input', function(){ const v=this.value.toLowerCase(); cy.nodes().forEach(n=>{ n.style('opacity', n.data('label').toLowerCase().includes(v)?1:0.2); }); });
</script>
</body>
</html>
HTML;
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}

	// -------------------------------------------------------------------------
	// GraphML
	// -------------------------------------------------------------------------

	/**
	 * Export as GraphML XML (Gephi/yEd compatible).
	 *
	 * @since 0.5.0
	 *
	 * @param object[] $nodes Node rows.
	 * @param object[] $edges Edge rows.
	 * @return string XML string.
	 */
	private static function to_graphml( array $nodes, array $edges ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<graphml xmlns="http://graphml.graphdrawing.org/graphml">' . "\n";
		$xml .= '  <key id="label" for="node" attr.name="label" attr.type="string"/>' . "\n";
		$xml .= '  <key id="type" for="node" attr.name="type" attr.type="string"/>' . "\n";
		$xml .= '  <key id="degree" for="node" attr.name="degree" attr.type="int"/>' . "\n";
		$xml .= '  <key id="relation" for="edge" attr.name="relation" attr.type="string"/>' . "\n";
		$xml .= '  <key id="confidence" for="edge" attr.name="confidence" attr.type="double"/>' . "\n";
		$xml .= '  <graph id="G" edgedefault="directed">' . "\n";

		foreach ( $nodes as $n ) {
			$xml .= '    <node id="' . esc_attr( $n->node_id ) . '">' . "\n";
			$xml .= '      <data key="label">' . esc_html( $n->label ) . '</data>' . "\n";
			$xml .= '      <data key="type">' . esc_html( $n->type ) . '</data>' . "\n";
			$xml .= '      <data key="degree">' . intval( $n->degree ) . '</data>' . "\n";
			$xml .= '    </node>' . "\n";
		}

		$edge_idx = 0;
		foreach ( $edges as $e ) {
			$xml .= '    <edge id="e' . $edge_idx++ . '" source="' . esc_attr( $e->source_node_id ) . '" target="' . esc_attr( $e->target_node_id ) . '">' . "\n";
			$xml .= '      <data key="relation">' . esc_html( $e->relation ) . '</data>' . "\n";
			$xml .= '      <data key="confidence">' . floatval( $e->confidence ) . '</data>' . "\n";
			$xml .= '    </edge>' . "\n";
		}

		$xml .= '  </graph>' . "\n";
		$xml .= '</graphml>' . "\n";
		return $xml;
	}

	// -------------------------------------------------------------------------
	// CSV
	// -------------------------------------------------------------------------

	/**
	 * Export as CSV (two strings: nodes.csv and edges.csv).
	 *
	 * @since 0.5.0
	 *
	 * @param object[] $nodes Node rows.
	 * @param object[] $edges Edge rows.
	 * @return array { nodes_csv: string, edges_csv: string }
	 */
	private static function to_csv( array $nodes, array $edges ) {
		$nodes_csv = "node_id,label,type,post_id,url,degree,community_id\n";
		foreach ( $nodes as $n ) {
			$nodes_csv .= self::csv_row( array(
				$n->node_id,
				$n->label,
				$n->type,
				$n->post_id,
				$n->url,
				$n->degree,
				$n->community_id,
			) );
		}

		$edges_csv = "source_node_id,target_node_id,relation,confidence,provenance\n";
		foreach ( $edges as $e ) {
			$edges_csv .= self::csv_row( array(
				$e->source_node_id,
				$e->target_node_id,
				$e->relation,
				$e->confidence,
				$e->provenance,
			) );
		}

		return array(
			'nodes_csv' => $nodes_csv,
			'edges_csv' => $edges_csv,
		);
	}

	/**
	 * Format one CSV row, properly quoting values.
	 *
	 * @since 0.5.0
	 *
	 * @param array $values Column values.
	 * @return string CSV line including newline.
	 */
	private static function csv_row( array $values ) {
		$quoted = array();
		foreach ( $values as $v ) {
			$v        = (string) $v;
			$v        = str_replace( '"', '""', $v );
			$quoted[] = '"' . $v . '"';
		}
		return implode( ',', $quoted ) . "\n";
	}

	// -------------------------------------------------------------------------
	// Neo4j Cypher
	// -------------------------------------------------------------------------

	/**
	 * Export as Neo4j Cypher CREATE statements.
	 *
	 * @since 0.5.0
	 *
	 * @param object[] $nodes Node rows.
	 * @param object[] $edges Edge rows.
	 * @return string Cypher script.
	 */
	private static function to_neo4j( array $nodes, array $edges ) {
		$cypher = "// NV oOS Graphify — Neo4j Cypher export\n";
		$cypher .= '// Generated: ' . gmdate( 'c' ) . "\n\n";

		foreach ( $nodes as $n ) {
			$label      = sanitize_text_field( $n->label );
			$type_label = sanitize_key( $n->type );
			$cypher    .= "CREATE (:`{$type_label}` {node_id: " . wp_json_encode( $n->node_id )
				. ', label: ' . wp_json_encode( $label )
				. ', degree: ' . intval( $n->degree )
				. ', url: ' . wp_json_encode( $n->url ) . "});\n";
		}
		$cypher .= "\n";

		foreach ( $edges as $e ) {
			$rel     = strtoupper( sanitize_key( str_replace( array( '-', ' ', '.' ), '_', $e->relation ) ) );
			$cypher .= 'MATCH (s {node_id: ' . wp_json_encode( $e->source_node_id ) . '}), '
				. '(t {node_id: ' . wp_json_encode( $e->target_node_id ) . '}) '
				. "CREATE (s)-[:`{$rel}` {confidence: " . floatval( $e->confidence ) . ", provenance: " . wp_json_encode( $e->provenance ) . "}]->(t);\n";
		}

		return $cypher;
	}

	// -------------------------------------------------------------------------
	// Obsidian Vault
	// -------------------------------------------------------------------------

	/**
	 * Export as an Obsidian vault: one Markdown file per community.
	 *
	 * Returns a JSON-encoded map of filename → content.
	 *
	 * @since 0.5.0
	 *
	 * @param object[] $nodes Node rows.
	 * @param object[] $edges Edge rows.
	 * @return string JSON map { "community-slug.md": "content", ... }
	 */
	private static function to_obsidian( array $nodes, array $edges ) {
		// Group nodes by community.
		$by_community = array();
		foreach ( $nodes as $n ) {
			$cid = $n->community_id ? sanitize_key( $n->community_id ) : 'uncategorized';
			$by_community[ $cid ][] = $n;
		}

		// Build adjacency for wikilinks.
		$adjacency = array();
		foreach ( $edges as $e ) {
			$adjacency[ $e->source_node_id ][] = $e->target_node_id;
		}

		$files     = array();
		$node_map  = array();
		foreach ( $nodes as $n ) {
			$node_map[ $n->node_id ] = $n;
		}

		foreach ( $by_community as $cid => $community_nodes ) {
			$filename = $cid . '.md';
			$md       = "# Community: {$cid}\n\n";
			$md      .= '_' . count( $community_nodes ) . " nodes_\n\n";

			foreach ( $community_nodes as $n ) {
				$md .= '## ' . esc_html( $n->label ) . "\n\n";
				if ( $n->url ) {
					$md .= '[View →](' . esc_url( $n->url ) . ")\n\n";
				}
				if ( ! empty( $adjacency[ $n->node_id ] ) ) {
					$md .= "**Linked to:**\n";
					foreach ( $adjacency[ $n->node_id ] as $neighbor_id ) {
						if ( isset( $node_map[ $neighbor_id ] ) ) {
							$nbr_label = $node_map[ $neighbor_id ]->label;
							$md       .= '- [[' . esc_html( $nbr_label ) . "]]\n";
						}
					}
					$md .= "\n";
				}
			}

			$files[ $filename ] = $md;
		}

		return wp_json_encode( $files, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch all edges where both endpoints are in the provided node set.
	 *
	 * @since 0.5.0
	 *
	 * @param string[] $node_ids Node IDs to filter by.
	 * @return object[] Edge rows.
	 */
	private static function edges_for_nodes( array $node_ids ) {
		if ( empty( $node_ids ) ) {
			return array();
		}

		global $wpdb;
		$edges_table = NV_oOS_Graphify_DB::edges_table();
		$placeholders = implode( ',', array_fill( 0, count( $node_ids ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$edges_table} WHERE source_node_id IN ({$placeholders}) AND target_node_id IN ({$placeholders})",
				array_merge( $node_ids, $node_ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Build Cytoscape.js element array from node/edge rows.
	 *
	 * @since 0.5.0
	 *
	 * @param object[] $nodes Node rows.
	 * @param object[] $edges Edge rows.
	 * @return array Cytoscape elements array.
	 */
	private static function build_cytoscape_elements( array $nodes, array $edges ) {
		$palette = array( '#e74c3c','#3498db','#2ecc71','#f39c12','#9b59b6','#1abc9c','#e67e22','#2980b9','#27ae60','#c0392b' );
		$type_colors = array();
		$type_index  = 0;

		$elements = array();
		foreach ( $nodes as $n ) {
			$type = $n->type;
			if ( ! isset( $type_colors[ $type ] ) ) {
				$type_colors[ $type ] = $palette[ $type_index % count( $palette ) ];
				$type_index++;
			}
			$elements[] = array(
				'data' => array(
					'id'           => $n->node_id,
					'label'        => $n->label,
					'type'         => $n->type,
					'degree'       => (int) $n->degree,
					'community_id' => $n->community_id,
					'url'          => $n->url,
					'color'        => $type_colors[ $type ],
				),
			);
		}

		foreach ( $edges as $e ) {
			$elements[] = array(
				'data' => array(
					'id'         => $e->source_node_id . '_' . $e->target_node_id . '_' . $e->relation,
					'source'     => $e->source_node_id,
					'target'     => $e->target_node_id,
					'relation'   => $e->relation,
					'confidence' => floatval( $e->confidence ),
				),
			);
		}

		return $elements;
	}
}
