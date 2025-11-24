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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view the site summary.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

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
