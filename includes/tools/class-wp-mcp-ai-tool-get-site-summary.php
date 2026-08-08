<?php
/**
 * Tool returning a high-level summary of the WordPress site.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides basic site metadata and content statistics.
 */
class WP_MCP_AI_Tool_Get_Site_Summary implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Ability_Interface {
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
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.0.0
	 * @return string
	 */
	public function get_ability_identifier() {
		return 'get-site-summary';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.0.0
	 * @return string
	 */
	public function get_ability_category() {
		return 'nvoos-site';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.0.0
	 * @return array
	 */
	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'message'          => array(
					'type'        => 'string',
					'description' => 'Human-readable site summary.',
				),
				'summary'          => array(
					'type'        => 'string',
					'description' => 'Human-readable site summary.',
				),
				'site_name'        => array(
					'type'        => 'string',
					'description' => 'The site name.',
				),
				'site_description' => array(
					'type'        => 'string',
					'description' => 'The site tagline/description.',
				),
				'site_url'         => array(
					'type'        => 'string',
					'description' => 'The site home URL.',
				),
				'admin_email'      => array(
					'type'        => 'string',
					'description' => 'The admin email address.',
				),
				'posts_published'  => array(
					'type'        => 'integer',
					'description' => 'Number of published posts.',
				),
				'pages_published'  => array(
					'type'        => 'integer',
					'description' => 'Number of published pages.',
				),
				'total_users'      => array(
					'type'        => 'integer',
					'description' => 'Total registered users.',
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	public function is_public_ability() {
		return true;
	}
}
