<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

use function absint;
use function sanitize_text_field;

/**
 * Tool: nvoos_graphify_get_community
 *
 * Returns all nodes belonging to a topic cluster/community.
 *
 * @since 1.0.0
 */
class GetCommunity extends AbstractTool
{
    /**
     * {@inheritdoc}
     */
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /** {@inheritdoc} */
    public function getSlug(): string
    {
        return 'nvoos_graphify_get_community';
    }

    /** {@inheritdoc} */
    public function getName(): string
    {
        return __( 'Get Knowledge Community', 'nvoos-graphify' );
    }

    /** {@inheritdoc} */
    public function getDescription(): string
    {
        return __( 'Return all nodes that belong to a specific topic cluster (community) in the knowledge graph. Communities are detected via modularity-based clustering. Provide a community_id (from nvoos_graphify_graph_stats or nvoos_graphify_god_nodes) or a node_id/label to look up which community a node belongs to.', 'nvoos-graphify' );
    }

    /** {@inheritdoc} */
    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'community_id' => array(
                    'type'        => 'string',
                    'description' => __( 'Community identifier (e.g. "c_ab12cd34"). From nvoos_graphify_graph_stats.', 'nvoos-graphify' ),
                ),
                'node_id'      => array(
                    'type'        => 'string',
                    'description' => __( 'Look up the community that contains this node.', 'nvoos-graphify' ),
                ),
                'label'        => array(
                    'type'        => 'string',
                    'description' => __( 'Find the community for the node matching this label.', 'nvoos-graphify' ),
                    'maxLength'   => 255,
                ),
                'limit'        => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum nodes to return (default: 50).', 'nvoos-graphify' ),
                    'minimum'     => 1,
                    'maximum'     => 200,
                    'default'     => 50,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /** {@inheritdoc} */
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
        $community_id = '';
        $limit        = isset( $arguments['limit'] ) ? max( 1, min( 200, absint( $arguments['limit'] ) ) ) : 50;

        if ( ! empty( $arguments['community_id'] ) ) {
            $community_id = sanitize_text_field( $arguments['community_id'] );
        } elseif ( ! empty( $arguments['node_id'] ) ) {
            $n = \NvoosGraphify\Graph\Db::getNode( sanitize_text_field( $arguments['node_id'] ) );
            if ( $n ) {
                $community_id = $n->community_id;
            }
        } elseif ( ! empty( $arguments['label'] ) ) {
            $results = \NvoosGraphify\Graph\Db::searchNodes( sanitize_text_field( $arguments['label'] ), '', 1 );
            if ( ! empty( $results ) ) {
                $community_id = $results[0]->community_id;
            }
        }

        if ( ! $community_id ) {
            return array(
                'success' => false,
                'error'   => __( 'Community not found. Provide a valid community_id, node_id, or label.', 'nvoos-graphify' ),
            );
        }

        $nodes = \NvoosGraphify\Graph\Db::listNodes(
            array(
                'community_id' => $community_id,
                'limit'        => $limit,
                'order_by'     => 'degree',
                'order'        => 'DESC',
            )
        );

        return array(
            'success'      => true,
            'community_id' => $community_id,
            'node_count'   => count( $nodes ),
            'nodes'        => $nodes,
        );
    }
}
