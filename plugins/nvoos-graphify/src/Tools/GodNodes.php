<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

use NvoosGraphify\Graph\Analyzer;

use function absint;
use function __;
use function _n;
use function sanitize_text_field;
use function sprintf;

/**
 * Tool: nvoos_graphify_god_nodes
 *
 * Returns the most-connected content pillars in the knowledge graph.
 *
 * @since 1.0.0
 */
class GodNodes extends AbstractTool
{
    public function getSlug(): string
    {
        return 'nvoos_graphify_god_nodes';
    }

    public function getName(): string
    {
        return __( 'Knowledge Graph God Nodes', 'nvoos-graphify' );
    }

    public function getDescription(): string
    {
        return __( 'Return the most-connected nodes in the knowledge graph — the "god nodes" or content pillars that act as hubs connecting many other pieces of content. High-degree nodes are ideal candidates for pillar pages, link-building targets, or topic cluster anchors.', 'nvoos-graphify' );
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'limit' => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of top nodes to return (default: 10, max: 50).', 'nvoos-graphify' ),
                    'minimum'     => 1,
                    'maximum'     => 50,
                    'default'     => 10,
                ),
                'type'  => array(
                    'type'        => 'string',
                    'description' => __( 'Filter by node type: post, page, term, topic, entity, user, media. Leave empty for all types.', 'nvoos-graphify' ),
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getCapabilityFlags(): array
    {
        return array( 'read-only', 'cacheable' );
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
        $limit = isset( $arguments['limit'] ) ? max( 1, min( 50, absint( $arguments['limit'] ) ) ) : 10;
        $type  = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : '';

        $nodes = Analyzer::getGodNodes( $limit, $type );

        $summary = sprintf(
            /* translators: %d: node count */
            _n(
                'Found %d content pillar node in the knowledge graph.',
                'Found %d content pillar nodes in the knowledge graph.',
                count( $nodes ),
                'nvoos-graphify'
            ),
            count( $nodes )
        );

        return array(
            'success'    => true,
            'god_nodes'  => $nodes,
            'node_count' => count( $nodes ),
            'summary'    => $summary,
        );
    }
}
