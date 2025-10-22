<?php
/**
 * Tool returning items registered via JetEngine (custom post types).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides access to JetEngine registered post types.
 */
class WP_MCP_AI_Tool_Get_JetEngine_Items implements WP_MCP_AI_Tool_Interface {
    /**
     * {@inheritdoc}
     */
    public function get_slug() {
        return 'get_jetengine_items';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name() {
        return __( 'Get JetEngine Items', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description() {
        return __( 'Returns content items from a JetEngine managed post type.', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_parameters_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'post_type' => array(
                    'type'        => 'string',
                    'description' => __( 'JetEngine post type slug to query.', 'wp-mcp-ai' ),
                ),
                'limit'     => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum number of items to retrieve.', 'wp-mcp-ai' ),
                    'minimum'     => 1,
                    'maximum'     => 50,
                    'default'     => 10,
                ),
            ),
            'required'             => array( 'post_type' ),
            'additionalProperties' => false,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute( array $arguments = array(), array $context = array() ) {
        if ( ! function_exists( 'jet_engine' ) ) {
            return new WP_Error( 'wp_mcp_ai_jetengine_missing', __( 'JetEngine is not active on this site.', 'wp-mcp-ai' ) );
        }

        $post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : '';
        if ( empty( $post_type ) ) {
            return new WP_Error( 'wp_mcp_ai_missing_post_type', __( 'A JetEngine post type must be provided.', 'wp-mcp-ai' ) );
        }

        $limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
        $limit = $limit > 0 ? min( $limit, 50 ) : 10;

        $items = get_posts( array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $results = array();
        foreach ( $items as $item ) {
            $results[] = array(
                'ID'        => $item->ID,
                'title'     => get_the_title( $item ),
                'permalink' => get_permalink( $item ),
                'excerpt'   => wp_trim_words( wp_strip_all_tags( $item->post_content ), 30 ),
                'date'      => get_the_date( DATE_W3C, $item ),
            );
        }

        return $results;
    }
}
