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
class WP_MCP_AI_Tool_Get_Site_Summary implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'Get Site Summary', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns the site name, description, URL, and basic content statistics.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view the site summary.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$post_counts = wp_count_posts( 'post' );
		$page_counts = wp_count_posts( 'page' );
		$users       = count_users();

		/* translators: %s: Site name */
		$summary_text = sprintf( __( 'Site: %s', 'mcp-ai-wpoos' ), get_bloginfo( 'name' ) );

		return array(
			'message'          => $summary_text,
			'summary'          => $summary_text,
			'site_name'        => get_bloginfo( 'name' ),
			'site_description' => get_bloginfo( 'description' ),
			'site_url'         => home_url(),
			'admin_email'      => get_bloginfo( 'admin_email' ),
			'posts_published'  => isset( $post_counts->publish ) ? (int) $post_counts->publish : 0,
			'pages_published'  => isset( $page_counts->publish ) ? (int) $page_counts->publish : 0,
			'total_users'      => isset( $users['total_users'] ) ? (int) $users['total_users'] : 0,
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'developer_technical',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'systems_administrator', 'web_developer' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
