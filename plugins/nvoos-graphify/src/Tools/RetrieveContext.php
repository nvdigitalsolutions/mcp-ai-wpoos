<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

use NvoosGraphify\Graph\Db;
use NvoosGraphify\Settings;

use function absint;
use function __;
use function get_transient;
use function sanitize_text_field;
use function set_transient;
use function sprintf;

/**
 * Tool: nvoos_graphify_retrieve_context
 *
 * Flagship RAG retrieval tool: given a question, returns grounded context
 * from the knowledge graph (nodes, edges, related content) ready to paste
 * into an AI prompt. Combines text search + graph traversal + optional
 * vector similarity.
 *
 * @since 1.0.0
 */
class RetrieveContext extends AbstractTool
{
    public function getSlug(): string
    {
        return 'nvoos_graphify_retrieve_context';
    }

    public function getName(): string
    {
        return __( 'Retrieve Knowledge Graph Context', 'nvoos-graphify' );
    }

    public function getDescription(): string
    {
        return __( 'Single-call RAG retrieval: given a question, returns grounded context from the knowledge graph (nodes, edges, related content) ready to paste directly into an AI prompt. Combines full-text node search with multi-hop graph traversal and optional vector similarity search for semantically rich results. Returns nodes, edges, and a pre-formatted context_text string. Use this before generating content to ground responses in your site\'s knowledge.', 'nvoos-graphify' );
    }

    public function getParametersSchema(): array
    {
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

    public function getCapabilityFlags(): array
    {
        return array( 'read-only', 'cacheable', 'external-api' );
    }

    /**
     * Execute the tool.
     *
     * @param array<string,mixed> $arguments Tool arguments.
     * @param array<string,mixed> $context   Execution context.
     * @return array<string,mixed>|\WP_Error
     */
    public function execute( array $arguments = array(), array $context = array() )
    {
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
        $seed_nodes = Db::searchNodes( $question, '', 5 );
        $node_ids   = array();
        foreach ( $seed_nodes as $n ) {
            $node_ids[ $n->node_id ] = $n;
        }

        // Step 2: vector search if enabled.
        if ( $use_vectors ) {
            $settings = Settings::all();
            if ( ! empty( $settings['embeddings_enabled'] ) ) {
                $query_vector = null;
                if ( function_exists( 'wp_mcp_ai_get_embedding' ) ) {
                    $embeddings_model = $settings['embeddings_model'] ?? null;
                    if ( ! $embeddings_model && class_exists( \NvoosGraphify\Memory\Embeddings::class ) ) {
                        $embeddings_model = \NvoosGraphify\Memory\Embeddings::DEFAULT_MODEL;
                    }
                    $vec = wp_mcp_ai_get_embedding( $question, $embeddings_model );
                    if ( is_array( $vec ) && ! empty( $vec ) ) {
                        $query_vector = $vec;
                    }
                }
                if ( $query_vector && class_exists( \NvoosGraphify\Memory\Embeddings::class ) ) {
                    $vector_results = \NvoosGraphify\Memory\Embeddings::search( $query_vector, 5 );
                    foreach ( $vector_results as $vr ) {
                        if ( ! isset( $node_ids[ $vr['node_id'] ] ) ) {
                            $n = Db::getNode( $vr['node_id'] );
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
        $frontier  = array_keys( $all_nodes );
        $all_count = count( $all_nodes );
        for ( $hop = 0; $hop < $hops && ! empty( $frontier ) && $all_count < $k; $hop++ ) {
            $next_frontier = array();
            foreach ( $frontier as $nid ) {
                if ( $all_count >= $k ) {
                    break;
                }
                $neighbor_ids = Db::getNeighborIds( $nid );
                foreach ( $neighbor_ids as $neighbor_id ) {
                    if ( ! isset( $all_nodes[ $neighbor_id ] ) && $all_count < $k ) {
                        $n = Db::getNode( $neighbor_id );
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
                $edges = Db::getEdgesForNode( $nid );
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
        $context_text = $this->buildContextText( $question, $result_nodes, $result_edges );

        $result = array(
            'success'      => true,
            'question'     => $question,
            'nodes'        => array_map( array( $this, 'formatNode' ), $result_nodes ),
            'edges'        => array_map( array( $this, 'formatEdge' ), $result_edges ),
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
     * @since 1.0.0
     *
     * @param string               $question The question.
     * @param array<object|array>  $nodes    Node objects.
     * @param array<object|array>  $edges    Edge objects.
     * @return string Formatted context text.
     */
    private function buildContextText( $question, array $nodes, array $edges )
    {
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
     * @return array<string,mixed>
     */
    private function formatNode( $node )
    {
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
     * @return array<string,mixed>
     */
    private function formatEdge( $edge )
    {
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
