<?php
/**
 * Tool returning a high-level summary of the WordPress site.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides basic site metadata and content statistics.
 */
class WP_MCP_AI_Tool_Get_Site_Summary implements WP_MCP_AI_Tool_Interface {
    /**
     * {@inheritdoc}
     */
    public function get_slug() {
        return 'get_site_summary';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name() {
        return __( 'Get Site Summary', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description() {
        return __( 'Returns the site name, description, URL, and basic content statistics.', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_parameters_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(),
            'additionalProperties' => false,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute( array $arguments = array(), array $context = array() ) {
        $post_counts = wp_count_posts( 'post' );
        $page_counts = wp_count_posts( 'page' );
        $users       = count_users();

        return array(
            'site_name'        => get_bloginfo( 'name' ),
            'site_description' => get_bloginfo( 'description' ),
            'site_url'         => home_url(),
            'admin_email'      => get_bloginfo( 'admin_email' ),
            'posts_published'  => isset( $post_counts->publish ) ? (int) $post_counts->publish : 0,
            'pages_published'  => isset( $page_counts->publish ) ? (int) $page_counts->publish : 0,
            'total_users'      => isset( $users['total_users'] ) ? (int) $users['total_users'] : 0,
        );
    }
}
