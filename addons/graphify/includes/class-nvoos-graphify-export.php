<?php
/**
 * NV oOS Graphify Addon — Multi-Format Export Engine
 *
 * Exports the knowledge graph in multiple formats: JSON (NetworkX node-link),
 * HTML (Cytoscape.js visualization), GraphML (Gephi/yEd), CSV, Cypher (Neo4j),
 * and Obsidian vault markdown.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-format export engine for the NV oOS Graphify knowledge graph.
 *
 * Reads persisted nodes and edges from the database and serializes them
 * into six interchange formats used by graph analysis tools, visualization
 * libraries, and personal knowledge management systems.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Export {

	/**
	 * Community color palette (D3 Category 10).
	 *
	 * @since 0.5.0
	 * @var array<int,string>
	 */
	const COMMUNITY_COLORS = array(
		'#1f77b4',
		'#ff7f0e',
		'#2ca02c',
		'#d62728',
		'#9467bd',
		'#8c564b',
		'#e377c2',
		'#7f7f7f',
		'#bcbd22',
		'#17becf',
	);

	/**
	 * Map of node_type values to Neo4j-style labels.
	 *
	 * @since 0.5.0
	 * @var array<string,string>
	 */
	const NEO4J_LABEL_MAP = array(
		'post'          => 'Post',
		'page'          => 'Page',
		'taxonomy_term' => 'Term',
		'user'          => 'User',
		'media'         => 'Media',
		'entity'        => 'Entity',
		'concept'       => 'Concept',
	);

	/**
	 * Graph identifier.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private $graph_id;

	/**
	 * Cached nodes array.
	 *
	 * @since 0.5.0
	 * @var array|null
	 */
	private $nodes_cache = null;

	/**
	 * Cached edges array.
	 *
	 * @since 0.5.0
	 * @var array|null
	 */
	private $edges_cache = null;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 *
	 * @param int $graph_id Knowledge graph identifier.
	 */
	public function __construct( $graph_id ) {
		$this->graph_id = absint( $graph_id );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Load all nodes for this graph.
	 *
	 * Results are cached in an instance property so subsequent calls
	 * within the same request return the same dataset.
	 *
	 * @since 0.5.0
	 *
	 * @return array List of node row objects.
	 */
	public function get_all_nodes() {
		if ( null !== $this->nodes_cache ) {
			return $this->nodes_cache;
		}

		global $wpdb;

		$table = NV_oOS_Graphify_Database::get_nodes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->nodes_cache = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, label, node_type, source_type, source_id, source_url, community_id, degree, metadata FROM {$table} WHERE graph_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->graph_id
			),
			ARRAY_A
		);

		if ( ! is_array( $this->nodes_cache ) ) {
			$this->nodes_cache = array();
		}

		return $this->nodes_cache;
	}

	/**
	 * Load all edges for this graph.
	 *
	 * Results are cached in an instance property so subsequent calls
	 * within the same request return the same dataset.
	 *
	 * @since 0.5.0
	 *
	 * @return array List of edge row objects.
	 */
	public function get_all_edges() {
		if ( null !== $this->edges_cache ) {
			return $this->edges_cache;
		}

		global $wpdb;

		$table = NV_oOS_Graphify_Database::get_edges_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->edges_cache = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_node_id, target_node_id, relation, confidence, confidence_score, metadata FROM {$table} WHERE graph_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->graph_id
			),
			ARRAY_A
		);

		if ( ! is_array( $this->edges_cache ) ) {
			$this->edges_cache = array();
		}

		return $this->edges_cache;
	}

	/**
	 * Convert a node label to a filesystem-safe filename.
	 *
	 * Lowercases the string, replaces whitespace with hyphens, strips
	 * non-alphanumeric characters (except hyphens), collapses consecutive
	 * hyphens, and truncates to 50 characters.
	 *
	 * @since 0.5.0
	 *
	 * @param string $label Raw node label.
	 * @return string Sanitized filename (without extension).
	 */
	public function sanitize_filename( $label ) {
		$name = strtolower( sanitize_text_field( $label ) );
		$name = preg_replace( '/\s+/', '-', $name );
		$name = preg_replace( '/[^a-z0-9\-]/', '', $name );
		$name = preg_replace( '/-+/', '-', $name );
		$name = trim( $name, '-' );

		if ( strlen( $name ) > 50 ) {
			$name = substr( $name, 0, 50 );
			$name = rtrim( $name, '-' );
		}

		return $name;
	}

	// ------------------------------------------------------------------
	// JSON Export (NetworkX node-link)
	// ------------------------------------------------------------------

	/**
	 * Export the knowledge graph as NetworkX-compatible node-link JSON.
	 *
	 * Produces a directed, non-multigraph JSON document with metadata,
	 * a nodes array, and a links array. Suitable for import into NetworkX,
	 * D3.js, or any tool that consumes the node-link format.
	 *
	 * @since 0.5.0
	 *
	 * @return string Pretty-printed JSON string.
	 */
	public function export_json() {
		$nodes = $this->get_all_nodes();
		$edges = $this->get_all_edges();

		$json_nodes = array();
		foreach ( $nodes as $node ) {
			$json_nodes[] = array(
				'id'           => $node['node_id'],
				'label'        => $node['label'],
				'node_type'    => $node['node_type'],
				'community_id' => (int) $node['community_id'],
				'degree'       => (int) $node['degree'],
				'source_url'   => $node['source_url'],
			);
		}

		$json_links = array();
		foreach ( $edges as $edge ) {
			$json_links[] = array(
				'source'           => $edge['source_node_id'],
				'target'           => $edge['target_node_id'],
				'relation'         => $edge['relation'],
				'confidence'       => $edge['confidence'],
				'confidence_score' => (float) $edge['confidence_score'],
			);
		}

		$data = array(
			'directed'   => true,
			'multigraph' => false,
			'graph'      => array(
				'name'       => 'WordPress Knowledge Graph',
				'node_count' => count( $json_nodes ),
				'edge_count' => count( $json_links ),
			),
			'nodes'      => $json_nodes,
			'links'      => $json_links,
		);

		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	// ------------------------------------------------------------------
	// HTML Export (Cytoscape.js)
	// ------------------------------------------------------------------

	/**
	 * Export the knowledge graph as a standalone HTML visualization.
	 *
	 * Produces a self-contained HTML page with embedded Cytoscape.js,
	 * fcose layout, search box, community legend, and info popup.
	 * The page uses CDN-hosted Cytoscape.js and cytoscape-fcose libraries.
	 *
	 * @since 0.5.0
	 *
	 * @return string Complete HTML document.
	 */
	public function export_html() {
		$nodes = $this->get_all_nodes();
		$edges = $this->get_all_edges();

		$site_name = sanitize_text_field( get_bloginfo( 'name' ) );
		$title     = 'WordPress Knowledge Graph — ' . $site_name;

		$cy_nodes = array();
		foreach ( $nodes as $node ) {
			$degree    = (int) $node['degree'];
			$community = (int) $node['community_id'];

			$cy_nodes[] = array(
				'data' => array(
					'id'           => $node['node_id'],
					'label'        => $node['label'],
					'node_type'    => $node['node_type'],
					'community_id' => $community,
					'degree'       => $degree,
					'source_url'   => $node['source_url'],
				),
			);
		}

		$cy_edges = array();
		foreach ( $edges as $edge ) {
			$cy_edges[] = array(
				'data' => array(
					'id'               => $edge['source_node_id'] . '-' . $edge['target_node_id'] . '-' . $edge['relation'],
					'source'           => $edge['source_node_id'],
					'target'           => $edge['target_node_id'],
					'relation'         => $edge['relation'],
					'confidence'       => $edge['confidence'],
					'confidence_score' => (float) $edge['confidence_score'],
				),
			);
		}

		// Collect unique communities for the legend.
		$community_ids = array();
		foreach ( $nodes as $node ) {
			$cid = (int) $node['community_id'];
			if ( ! in_array( $cid, $community_ids, true ) ) {
				$community_ids[] = $cid;
			}
		}
		sort( $community_ids );

		$elements_json = wp_json_encode(
			array(
				'nodes' => $cy_nodes,
				'edges' => $cy_edges,
			),
			JSON_UNESCAPED_SLASHES
		);

		$colors_json = wp_json_encode( self::COMMUNITY_COLORS );

		$legend_html = '';
		foreach ( $community_ids as $cid ) {
			$color        = self::COMMUNITY_COLORS[ $cid % count( self::COMMUNITY_COLORS ) ];
			$escaped_cid  = (int) $cid;
			$legend_html .= '<span class="legend-item">'
				. '<span class="legend-dot" style="background:' . esc_attr( $color ) . '"></span>'
				. 'Community ' . $escaped_cid
				. '</span>';
		}

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $title ); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0f0f1a;color:#e0e0e0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;overflow:hidden}
#cy{width:100vw;height:100vh;position:absolute;top:0;left:0}
#controls{position:fixed;top:16px;left:16px;z-index:10;display:flex;flex-direction:column;gap:8px;max-width:320px}
#controls h1{font-size:14px;color:#00d4ff;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#search{padding:8px 12px;border:1px solid #2d2d44;border-radius:6px;background:#1a1a2e;color:#e0e0e0;font-size:13px;width:100%}
#search:focus{outline:none;border-color:#00d4ff}
#legend{display:flex;flex-wrap:wrap;gap:6px;margin-top:4px}
.legend-item{display:flex;align-items:center;gap:4px;font-size:11px;color:#999}
.legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
#info-popup{display:none;position:fixed;bottom:16px;right:16px;z-index:10;background:#1a1a2e;border:1px solid #2d2d44;border-radius:8px;padding:16px;min-width:240px;max-width:360px;font-size:13px;line-height:1.5}
#info-popup h2{font-size:15px;color:#00d4ff;margin-bottom:8px}
#info-popup .field{color:#999}
#info-popup .value{color:#e0e0e0}
#info-popup a{color:#00d4ff;text-decoration:none}
#info-popup a:hover{text-decoration:underline}
</style>
</head>
<body>
<div id="controls">
	<h1><?php echo esc_html( $title ); ?></h1>
	<input type="text" id="search" placeholder="Search nodes…" autocomplete="off">
	<div id="legend"><?php echo $legend_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr above ?></div>
</div>
<div id="cy"></div>
<div id="info-popup"></div>
<script src="https://unpkg.com/cytoscape@3.30.4/dist/cytoscape.min.js"></script>
<script src="https://unpkg.com/cytoscape-fcose@2.2.0/cytoscape-fcose.js"></script>
<script>
(function(){
	var COLORS = <?php echo $colors_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON array of hex strings ?>;
	var elements = <?php echo $elements_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON graph data ?>;

	/* Register fcose layout */
	if(typeof cytoscapeFcose !== 'undefined'){cytoscape.use(cytoscapeFcose);}

	var cy = cytoscape({
		container: document.getElementById('cy'),
		elements: elements,
		style: [
			{selector:'node', style:{
				'label':'data(label)',
				'font-size':'10px',
				'color':'#e0e0e0',
				'text-outline-color':'#0f0f1a',
				'text-outline-width':2,
				'text-valign':'bottom',
				'text-margin-y':4,
				'width': function(ele){return Math.max(20, Math.min(60, 20 + (ele.data('degree') || 0) * 2));},
				'height': function(ele){return Math.max(20, Math.min(60, 20 + (ele.data('degree') || 0) * 2));},
				'background-color': function(ele){return COLORS[(ele.data('community_id') || 0) % COLORS.length];},
				'border-width':1,
				'border-color':'#2d2d44'
			}},
			{selector:'edge', style:{
				'width':1.5,
				'line-color':'#444',
				'target-arrow-color':'#444',
				'target-arrow-shape':'triangle',
				'curve-style':'bezier',
				'arrow-scale':0.7
			}},
			{selector:'edge[confidence="EXTRACTED"]', style:{'line-style':'solid','line-color':'#666','target-arrow-color':'#666'}},
			{selector:'edge[confidence="INFERRED"]', style:{'line-style':'dashed','line-color':'#555','target-arrow-color':'#555'}},
			{selector:'edge[confidence="AMBIGUOUS"]', style:{'line-style':'dotted','line-color':'#444','target-arrow-color':'#444'}},
			{selector:'node.highlighted', style:{'border-width':3,'border-color':'#00d4ff'}},
			{selector:'node.faded', style:{'opacity':0.15}},
			{selector:'edge.faded', style:{'opacity':0.08}}
		],
		layout:{name:'fcose',animate:false,quality:'proof',nodeSeparation:80,idealEdgeLength:120}
	});

	/* Search filtering */
	var searchBox = document.getElementById('search');
	searchBox.addEventListener('input', function(){
		var q = this.value.trim().toLowerCase();
		if(!q){
			cy.elements().removeClass('faded highlighted');
			return;
		}
		cy.batch(function(){
			cy.elements().addClass('faded').removeClass('highlighted');
			var matched = cy.nodes().filter(function(n){return n.data('label').toLowerCase().indexOf(q) !== -1;});
			matched.removeClass('faded').addClass('highlighted');
			matched.connectedEdges().removeClass('faded');
			matched.neighborhood('node').removeClass('faded');
		});
	});

	/* Click node info popup */
	var popup = document.getElementById('info-popup');
	cy.on('tap','node', function(evt){
		var d = evt.target.data();
		var html = '<h2>' + escHtml(d.label) + '</h2>';
		html += '<div><span class="field">Type: </span><span class="value">' + escHtml(d.node_type) + '</span></div>';
		html += '<div><span class="field">Community: </span><span class="value">' + d.community_id + '</span></div>';
		html += '<div><span class="field">Degree: </span><span class="value">' + d.degree + '</span></div>';
		if(d.source_url){
			html += '<div><span class="field">URL: </span><a href="' + escAttr(d.source_url) + '" target="_blank" rel="noopener">' + escHtml(d.source_url) + '</a></div>';
		}
		popup.innerHTML = html;
		popup.style.display = 'block';
	});
	cy.on('tap', function(evt){
		if(evt.target === cy){popup.style.display = 'none';}
	});

	function escHtml(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;}
	function escAttr(s){return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
})();
</script>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	// ------------------------------------------------------------------
	// GraphML Export
	// ------------------------------------------------------------------

	/**
	 * Export the knowledge graph as GraphML XML.
	 *
	 * Produces a standards-compliant GraphML document suitable for import
	 * into Gephi, yEd, or any tool that supports GraphML.
	 *
	 * @since 0.5.0
	 *
	 * @return string GraphML XML document.
	 */
	public function export_graphml() {
		$nodes = $this->get_all_nodes();
		$edges = $this->get_all_edges();

		$lines   = array();
		$lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$lines[] = '<graphml xmlns="http://graphml.graphstruct.org/xmlns">';

		// Attribute key definitions — nodes.
		$lines[] = '  <key id="label" for="node" attr.name="label" attr.type="string"/>';
		$lines[] = '  <key id="node_type" for="node" attr.name="node_type" attr.type="string"/>';
		$lines[] = '  <key id="community_id" for="node" attr.name="community_id" attr.type="int"/>';
		$lines[] = '  <key id="degree" for="node" attr.name="degree" attr.type="int"/>';
		$lines[] = '  <key id="source_url" for="node" attr.name="source_url" attr.type="string"/>';

		// Attribute key definitions — edges.
		$lines[] = '  <key id="relation" for="edge" attr.name="relation" attr.type="string"/>';
		$lines[] = '  <key id="confidence" for="edge" attr.name="confidence" attr.type="string"/>';
		$lines[] = '  <key id="confidence_score" for="edge" attr.name="confidence_score" attr.type="double"/>';

		$lines[] = '  <graph id="G" edgedefault="directed">';

		foreach ( $nodes as $node ) {
			$lines[] = '    <node id="' . esc_attr( $node['node_id'] ) . '">';
			$lines[] = '      <data key="label">' . esc_html( $node['label'] ) . '</data>';
			$lines[] = '      <data key="node_type">' . esc_html( $node['node_type'] ) . '</data>';
			$lines[] = '      <data key="community_id">' . (int) $node['community_id'] . '</data>';
			$lines[] = '      <data key="degree">' . (int) $node['degree'] . '</data>';
			$lines[] = '      <data key="source_url">' . esc_html( $node['source_url'] ) . '</data>';
			$lines[] = '    </node>';
		}

		foreach ( $edges as $edge ) {
			$lines[] = '    <edge source="' . esc_attr( $edge['source_node_id'] ) . '" target="' . esc_attr( $edge['target_node_id'] ) . '">';
			$lines[] = '      <data key="relation">' . esc_html( $edge['relation'] ) . '</data>';
			$lines[] = '      <data key="confidence">' . esc_html( $edge['confidence'] ) . '</data>';
			$lines[] = '      <data key="confidence_score">' . (float) $edge['confidence_score'] . '</data>';
			$lines[] = '    </edge>';
		}

		$lines[] = '  </graph>';
		$lines[] = '</graphml>';

		return implode( "\n", $lines );
	}

	// ------------------------------------------------------------------
	// CSV Export
	// ------------------------------------------------------------------

	/**
	 * Export the knowledge graph as CSV.
	 *
	 * Returns an associative array with two keys — 'nodes' and 'edges' —
	 * each containing a complete CSV string including the header row.
	 *
	 * @since 0.5.0
	 *
	 * @return array{nodes: string, edges: string}
	 */
	public function export_csv() {
		$nodes = $this->get_all_nodes();
		$edges = $this->get_all_edges();

		// --- Nodes CSV ---
		$nodes_lines   = array();
		$nodes_lines[] = 'node_id,label,node_type,community_id,degree,source_url';

		foreach ( $nodes as $node ) {
			$nodes_lines[] = $this->csv_row(
				array(
					$node['node_id'],
					$node['label'],
					$node['node_type'],
					(int) $node['community_id'],
					(int) $node['degree'],
					$node['source_url'],
				)
			);
		}

		// --- Edges CSV ---
		$edges_lines   = array();
		$edges_lines[] = 'source_node_id,target_node_id,relation,confidence,confidence_score';

		foreach ( $edges as $edge ) {
			$edges_lines[] = $this->csv_row(
				array(
					$edge['source_node_id'],
					$edge['target_node_id'],
					$edge['relation'],
					$edge['confidence'],
					(float) $edge['confidence_score'],
				)
			);
		}

		return array(
			'nodes' => implode( "\n", $nodes_lines ),
			'edges' => implode( "\n", $edges_lines ),
		);
	}

	/**
	 * Format an array of values as a single CSV row.
	 *
	 * Strings containing commas, double-quotes, or newlines are
	 * enclosed in double-quotes with internal quotes escaped.
	 *
	 * @since 0.5.0
	 *
	 * @param array $fields Row values.
	 * @return string Formatted CSV line.
	 */
	private function csv_row( $fields ) {
		$escaped = array();
		foreach ( $fields as $value ) {
			$value = (string) $value;
			if ( strpos( $value, ',' ) !== false || strpos( $value, '"' ) !== false || strpos( $value, "\n" ) !== false ) {
				$value = '"' . str_replace( '"', '""', $value ) . '"';
			}
			$escaped[] = $value;
		}
		return implode( ',', $escaped );
	}

	// ------------------------------------------------------------------
	// Cypher Export (Neo4j)
	// ------------------------------------------------------------------

	/**
	 * Export the knowledge graph as Neo4j Cypher CREATE statements.
	 *
	 * Generates a sequence of Cypher statements that can be executed in
	 * the Neo4j browser or via cypher-shell to recreate the graph.
	 *
	 * @since 0.5.0
	 *
	 * @return string Cypher statements separated by newlines.
	 */
	public function export_cypher() {
		$nodes = $this->get_all_nodes();
		$edges = $this->get_all_edges();

		$statements = array();

		// --- Node CREATE statements ---
		foreach ( $nodes as $node ) {
			$neo4j_label = $this->neo4j_label( $node['node_type'] );
			$id          = $this->cypher_escape( $node['node_id'] );
			$label       = $this->cypher_escape( $node['label'] );
			$degree      = (int) $node['degree'];
			$community   = (int) $node['community_id'];
			$url         = $this->cypher_escape( $node['source_url'] );

			$statements[] = sprintf(
				"CREATE (n:%s {id: '%s', label: '%s', degree: %d, community_id: %d, source_url: '%s'})",
				$neo4j_label,
				$id,
				$label,
				$degree,
				$community,
				$url
			);
		}

		// --- Edge CREATE statements ---
		foreach ( $edges as $edge ) {
			$rel_type   = strtoupper( sanitize_text_field( $edge['relation'] ) );
			$rel_type   = preg_replace( '/[^A-Z0-9_]/', '_', $rel_type );
			$source     = $this->cypher_escape( $edge['source_node_id'] );
			$target     = $this->cypher_escape( $edge['target_node_id'] );
			$confidence = $this->cypher_escape( $edge['confidence'] );
			$score      = (float) $edge['confidence_score'];

			$statements[] = sprintf(
				"MATCH (a {id: '%s'}), (b {id: '%s'})\nCREATE (a)-[:%s {confidence: '%s', score: %s}]->(b)",
				$source,
				$target,
				$rel_type,
				$confidence,
				$score
			);
		}

		return implode( ";\n", $statements ) . ';';
	}

	/**
	 * Resolve a node_type to a Neo4j label.
	 *
	 * Falls back to a sanitized, ucfirst version of the type string
	 * when the type is not in the predefined map.
	 *
	 * @since 0.5.0
	 *
	 * @param string $node_type WordPress node type.
	 * @return string Neo4j-safe label.
	 */
	private function neo4j_label( $node_type ) {
		if ( isset( self::NEO4J_LABEL_MAP[ $node_type ] ) ) {
			return self::NEO4J_LABEL_MAP[ $node_type ];
		}

		$label = preg_replace( '/[^a-zA-Z0-9_]/', '', ucfirst( $node_type ) );
		return $label ? $label : 'Node';
	}

	/**
	 * Escape a string for use inside Cypher single-quoted literals.
	 *
	 * @since 0.5.0
	 *
	 * @param string $value Raw value.
	 * @return string Escaped value.
	 */
	private function cypher_escape( $value ) {
		$value = sanitize_text_field( (string) $value );
		$value = str_replace( '\\', '\\\\', $value );
		$value = str_replace( "'", "\\'", $value );
		return $value;
	}

	// ------------------------------------------------------------------
	// Obsidian Vault Export
	// ------------------------------------------------------------------

	/**
	 * Export the knowledge graph as an Obsidian vault.
	 *
	 * Returns an associative array of Markdown files keyed by filename.
	 * The vault includes an index file, per-community overview files,
	 * and individual node files with wikilinks for cross-referencing.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string,string> Filename => Markdown content.
	 */
	public function export_obsidian() {
		$nodes = $this->get_all_nodes();
		$edges = $this->get_all_edges();

		// Group nodes by community.
		$communities = array();
		foreach ( $nodes as $node ) {
			$cid = (int) $node['community_id'];
			if ( ! isset( $communities[ $cid ] ) ) {
				$communities[ $cid ] = array();
			}
			$communities[ $cid ][] = $node;
		}
		ksort( $communities );

		// Build lookup of node_id → label for wikilinks.
		$label_map = array();
		foreach ( $nodes as $node ) {
			$label_map[ $node['node_id'] ] = $node['label'];
		}

		// Build adjacency lists: node_id → array of { target_label, relation }.
		$outgoing = array();
		$incoming = array();
		foreach ( $edges as $edge ) {
			$src = $edge['source_node_id'];
			$tgt = $edge['target_node_id'];

			$src_label = isset( $label_map[ $src ] ) ? $label_map[ $src ] : $src;
			$tgt_label = isset( $label_map[ $tgt ] ) ? $label_map[ $tgt ] : $tgt;

			if ( ! isset( $outgoing[ $src ] ) ) {
				$outgoing[ $src ] = array();
			}
			$outgoing[ $src ][] = array(
				'label'    => $tgt_label,
				'relation' => $edge['relation'],
			);

			if ( ! isset( $incoming[ $tgt ] ) ) {
				$incoming[ $tgt ] = array();
			}
			$incoming[ $tgt ][] = array(
				'label'    => $src_label,
				'relation' => $edge['relation'],
			);
		}

		// Determine community labels (highest-degree node label).
		$community_labels = array();
		foreach ( $communities as $cid => $members ) {
			$best_label  = '';
			$best_degree = -1;
			foreach ( $members as $member ) {
				if ( (int) $member['degree'] > $best_degree ) {
					$best_degree = (int) $member['degree'];
					$best_label  = $member['label'];
				}
			}
			$community_labels[ $cid ] = $best_label;
		}

		$files = array();

		// --- Index file ---
		$index_lines   = array();
		$index_lines[] = '# Knowledge Graph';
		$index_lines[] = '';
		$index_lines[] = '## Communities';
		$index_lines[] = '';

		foreach ( $communities as $cid => $members ) {
			$clabel        = $community_labels[ $cid ];
			$count         = count( $members );
			$safe_filename = 'community-' . $cid . '-' . $this->sanitize_filename( $clabel );
			$index_lines[] = '- [[' . $safe_filename . '|Community ' . $cid . ': ' . $clabel . ']] (' . $count . ' members)';
		}

		$index_lines[] = '';
		$index_lines[] = '---';
		$index_lines[] = '';
		$index_lines[] = 'Total nodes: ' . count( $nodes );
		$index_lines[] = 'Total edges: ' . count( $edges );

		$files['index.md'] = implode( "\n", $index_lines );

		// --- Community files ---
		foreach ( $communities as $cid => $members ) {
			$clabel        = $community_labels[ $cid ];
			$safe_filename = 'community-' . $cid . '-' . $this->sanitize_filename( $clabel );

			$clines   = array();
			$clines[] = '# ' . $clabel;
			$clines[] = '';
			$clines[] = 'Community: ' . $cid;
			$clines[] = 'Members: ' . count( $members );
			$clines[] = '';
			$clines[] = '## Members';
			$clines[] = '';

			foreach ( $members as $member ) {
				$node_filename = $this->sanitize_filename( $member['node_type'] ) . '-' . $this->sanitize_filename( $member['label'] );
				$clines[]      = '- [[' . $node_filename . '|' . $member['label'] . ']]';
			}

			$files[ $safe_filename . '.md' ] = implode( "\n", $clines );
		}

		// --- Individual node files ---
		foreach ( $nodes as $node ) {
			$node_filename = $this->sanitize_filename( $node['node_type'] ) . '-' . $this->sanitize_filename( $node['label'] );
			$cid           = (int) $node['community_id'];
			$clabel        = isset( $community_labels[ $cid ] ) ? $community_labels[ $cid ] : 'Unknown';

			$nlines   = array();
			$nlines[] = '# ' . $node['label'];
			$nlines[] = '';
			$nlines[] = 'Type: ' . $node['node_type'];
			$nlines[] = 'Community: ' . $clabel;
			$nlines[] = 'Degree: ' . (int) $node['degree'];

			if ( ! empty( $node['source_url'] ) ) {
				$nlines[] = 'URL: ' . $node['source_url'];
			}

			// Outgoing links.
			if ( ! empty( $outgoing[ $node['node_id'] ] ) ) {
				$nlines[] = '';
				$nlines[] = '## Links';
				$nlines[] = '';
				foreach ( $outgoing[ $node['node_id'] ] as $link ) {
					$target_node     = $this->find_node_by_label( $nodes, $link['label'] );
					$target_filename = $target_node
						? $this->sanitize_filename( $target_node['node_type'] ) . '-' . $this->sanitize_filename( $target_node['label'] )
						: $this->sanitize_filename( $link['label'] );
					$nlines[]        = '- [[' . $target_filename . '|' . $link['label'] . ']] (' . $link['relation'] . ')';
				}
			}

			// Incoming links.
			if ( ! empty( $incoming[ $node['node_id'] ] ) ) {
				$nlines[] = '';
				$nlines[] = '## Linked From';
				$nlines[] = '';
				foreach ( $incoming[ $node['node_id'] ] as $link ) {
					$source_node     = $this->find_node_by_label( $nodes, $link['label'] );
					$source_filename = $source_node
						? $this->sanitize_filename( $source_node['node_type'] ) . '-' . $this->sanitize_filename( $source_node['label'] )
						: $this->sanitize_filename( $link['label'] );
					$nlines[]        = '- [[' . $source_filename . '|' . $link['label'] . ']] (' . $link['relation'] . ')';
				}
			}

			$files[ $node_filename . '.md' ] = implode( "\n", $nlines );
		}

		return $files;
	}

	/**
	 * Find a node row by its label.
	 *
	 * Used internally by the Obsidian exporter to resolve wikilink
	 * filenames from label strings.
	 *
	 * @since 0.5.0
	 *
	 * @param array  $nodes All node rows.
	 * @param string $label Label to search for.
	 * @return array|null Node row or null if not found.
	 */
	private function find_node_by_label( $nodes, $label ) {
		foreach ( $nodes as $node ) {
			if ( $node['label'] === $label ) {
				return $node;
			}
		}
		return null;
	}
}
