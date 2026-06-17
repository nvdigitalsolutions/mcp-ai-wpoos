<?php
/**
 * Graphify Tool — Retrieve Knowledge Graph Context
 *
 * Flagship RAG retrieval tool: given a question, returns grounded context
 * from the knowledge graph (nodes, edges, related content) ready to paste
 * into an AI prompt. Combines text search + graph traversal + optional
 * vector similarity.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_retrieve_context
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Tool_Retrieve_Context implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_retrieve_context';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Retrieve Knowledge Graph Context', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Single-call RAG retrieval: given a question, returns grounded context from the knowledge graph (nodes, edges, related content) ready to paste directly into an AI prompt. Combines full-text node search with multi-hop graph traversal and optional vector similarity search for semantically rich results. Returns nodes, edges, and a pre-formatted context_text string. Use this before generating content to ground responses in your site\'s knowledge.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'question'      => array(
					'type'        => 'string',
					'description' => __( 'The question or topic to retrieve context for.', 'nvoos-graphify' ),
					'maxLength'   => 1000,
				),
				'hops'          => array(
					'type'        => 'integer',
					'description' => __( 'Number of graph hops from seed nodes for traversal (1-3).', 'nvoos-graphify' ),
					'minimum'     => 1,
					'maximum'     => 3,
					'default'     => 2,
				),
				'k'             => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of nodes to return (1-20).', 'nvoos-graphify' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 10,
				),
				'use_vectors'   => array(
					'type'        => 'boolean',
					'description' => __( 'Use vector similarity search in addition to text search (requires embeddings to be indexed).', 'nvoos-graphify' ),
					'default'     => false,
				),
				'include_edges' => array(
					'type'        => 'boolean',
					'description' => __( 'Include edges between the returned nodes.', 'nvoos-graphify' ),
					'default'     => true,
				),
			),
			'required'             => array( 'question' ),
			'additionalProperties' => false,
		);
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array( 'read-only', 'cacheable', 'external-api' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$question      = sanitize_text_field( $arguments['question'] ?? '' );
		$hops          = max( 1, min( 3, absint( $arguments['hops'] ?? 2 ) ) );
		$k             = max( 1, min( 20, absint( $arguments['k'] ?? 10 ) ) );
		$use_vectors   = ! empty( $arguments['use_vectors'] );
		$include_edges = isset( $arguments['include_edges'] ) ? (bool) $arguments['include_edges'] : true;

		if ( empty( $question ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Question is required.', 'nvoos-graphify' ),
			);
		}

		// Cache check.
		$cache_key = 'nvoos_graphify_rag_' . md5( $question . $hops . $k . ( $use_vectors ? '1' : '0' ) . ( $include_edges ? '1' : '0' ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			$cached['cache_hit'] = true;
			return $cached;
		}

		// Step 1: text search for seed nodes.
		$seed_nodes = NV_oOS_Graphify_DB::search_nodes( $question, '', 5 );
		$node_ids   = array();
		foreach ( $seed_nodes as $n ) {
			$node_ids[ $n->node_id ] = $n;
		}

		// Step 2: vector search if enabled.
		if ( $use_vectors ) {
			$settings = NV_oOS_Graphify::get_settings();
			if ( ! empty( $settings['embeddings_enabled'] ) ) {
				$query_vector = null;
				if ( function_exists( 'wp_mcp_ai_get_embedding' ) ) {
					$vec = wp_mcp_ai_get_embedding( $question, $settings['embeddings_model'] ?? NV_oOS_Graphify_Embeddings::DEFAULT_MODEL );
					if ( is_array( $vec ) && ! empty( $vec ) ) {
						$query_vector = $vec;
					}
				}
				if ( $query_vector ) {
					$vector_results = NV_oOS_Graphify_Embeddings::search( $query_vector, 5 );
					foreach ( $vector_results as $vr ) {
						if ( ! isset( $node_ids[ $vr['node_id'] ] ) ) {
							$n = NV_oOS_Graphify_DB::get_node( $vr['node_id'] );
							if ( $n ) {
								$node_ids[ $n->node_id ] = $n;
							}
						}
					}
				}
			}
		}

		// Step 3: BFS traversal up to $hops.
		$all_nodes = $node_ids;
		$frontier  = array_keys( $node_ids );
		$all_count = count( $all_nodes );
		for ( $hop = 0; $hop < $hops && ! empty( $frontier ) && $all_count < $k; $hop++ ) {
			$next_frontier = array();
			foreach ( $frontier as $nid ) {
				if ( $all_count >= $k ) {
					break;
				}
				$neighbor_ids = NV_oOS_Graphify_DB::get_neighbor_ids( $nid );
				foreach ( $neighbor_ids as $neighbor_id ) {
					if ( ! isset( $all_nodes[ $neighbor_id ] ) && $all_count < $k ) {
						$n = NV_oOS_Graphify_DB::get_node( $neighbor_id );
						if ( $n ) {
							$all_nodes[ $neighbor_id ] = $n;
							$next_frontier[]           = $neighbor_id;
							$all_count                 = count( $all_nodes );
						}
					}
				}
			}
			$frontier = $next_frontier;
		}

		$result_nodes = array_values( $all_nodes );

		// Step 4: collect edges between returned nodes.
		$result_edges = array();
		if ( $include_edges && ! empty( $result_nodes ) ) {
			$all_node_ids = array_keys( $all_nodes );
			foreach ( $all_node_ids as $nid ) {
				$edges = NV_oOS_Graphify_DB::get_edges_for_node( $nid );
				foreach ( $edges as $edge ) {
					if ( in_array( $edge->source_node_id, $all_node_ids, true ) && in_array( $edge->target_node_id, $all_node_ids, true ) ) {
						$edge_key = $edge->source_node_id . '_' . $edge->relation . '_' . $edge->target_node_id;
						if ( ! isset( $result_edges[ $edge_key ] ) ) {
							$result_edges[ $edge_key ] = $edge;
						}
					}
				}
			}
			$result_edges = array_values( $result_edges );
		}

		// Step 5: build context_text.
		$context_text = $this->build_context_text( $question, $result_nodes, $result_edges );

		$result = array(
			'success'      => true,
			'question'     => $question,
			'nodes'        => array_map( array( $this, 'format_node' ), $result_nodes ),
			'edges'        => array_map( array( $this, 'format_edge' ), $result_edges ),
			'context_text' => $context_text,
			'cache_hit'    => false,
		);

		// Cache for 5 minutes.
		set_transient( $cache_key, $result, 300 );

		return $result;
	}

	/**
	 * Build a human-readable context string for use in AI prompts.
	 *
	 * @since 0.6.0
	 *
	 * @param string $question     The question.
	 * @param array  $nodes        Node objects.
	 * @param array  $edges        Edge objects.
	 * @return string Formatted context text.
	 */
	private function build_context_text( $question, array $nodes, array $edges ) {
		if ( empty( $nodes ) ) {
			return sprintf( __( 'No knowledge graph context found for: %s', 'nvoos-graphify' ), $question );
		}

		$lines   = array();
		$lines[] = '## Knowledge Graph Context';
		$lines[] = sprintf( '**Query:** %s', $question );
		$lines[] = '';
		$lines[] = '### Entities';

		foreach ( $nodes as $node ) {
			$label = is_object( $node ) ? $node->label : ( $node['label'] ?? '' );
			$type  = is_object( $node ) ? $node->type : ( $node['type'] ?? '' );
			$url   = is_object( $node ) ? $node->url : ( $node['url'] ?? '' );
			$line  = sprintf( '- **%s** (%s)', $label, $type );
			if ( $url ) {
				$line .= sprintf( ' — %s', $url );
			}
			$lines[] = $line;
		}

		if ( ! empty( $edges ) ) {
			$lines[] = '';
			$lines[] = '### Relationships';

			// Build a lookup by node_id -> label.
			$node_labels = array();
			foreach ( $nodes as $node ) {
				$nid   = is_object( $node ) ? $node->node_id : ( $node['node_id'] ?? '' );
				$label = is_object( $node ) ? $node->label : ( $node['label'] ?? '' );
				if ( $nid ) {
					$node_labels[ $nid ] = $label;
				}
			}

			foreach ( $edges as $edge ) {
				$src       = is_object( $edge ) ? $edge->source_node_id : ( $edge['source_node_id'] ?? '' );
				$tgt       = is_object( $edge ) ? $edge->target_node_id : ( $edge['target_node_id'] ?? '' );
				$rel       = is_object( $edge ) ? $edge->relation : ( $edge['relation'] ?? '' );
				$src_label = isset( $node_labels[ $src ] ) ? $node_labels[ $src ] : $src;
				$tgt_label = isset( $node_labels[ $tgt ] ) ? $node_labels[ $tgt ] : $tgt;
				$lines[]   = sprintf( '- %s → **%s** → %s', $src_label, $rel, $tgt_label );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format a node object for the response.
	 *
	 * @param object|array $node Node.
	 * @return array
	 */
	private function format_node( $node ) {
		if ( is_object( $node ) ) {
			return array(
				'node_id'    => $node->node_id,
				'label'      => $node->label,
				'type'       => $node->type,
				'url'        => $node->url,
				'degree'     => $node->degree,
				'community'  => $node->community_id,
				'properties' => is_string( $node->properties ) ? json_decode( $node->properties, true ) : $node->properties,
			);
		}
		return (array) $node;
	}

	/**
	 * Format an edge object for the response.
	 *
	 * @param object|array $edge Edge.
	 * @return array
	 */
	private function format_edge( $edge ) {
		if ( is_object( $edge ) ) {
			return array(
				'source'     => $edge->source_node_id,
				'target'     => $edge->target_node_id,
				'relation'   => $edge->relation,
				'confidence' => $edge->confidence,
				'provenance' => $edge->provenance,
			);
		}
		return (array) $edge;
	}
}
