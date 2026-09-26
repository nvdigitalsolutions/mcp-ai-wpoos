<?php
/**
 * Tool: get_site_settings — Retrieves core WordPress site settings.
 *
 * Port of mcp-wordpress wp_get_site_settings tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves the core WordPress general settings as a structured payload.
 *
 * Mirrors mcp-wordpress's wp_get_site_settings: title, tagline, URLs, admin
 * email, localization, date/time formats, and content/discussion settings.
 */
class WP_MCP_AI_Tool_Get_Site_Settings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_site_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Site Settings', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves the core WordPress general settings (site title, tagline, admin email, timezone, date/time formats, reading and discussion settings).', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Inspecting or reporting the site configuration before changing it with update_site_settings.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Checking WP/PHP/plugin versions; use get_environment_status. Checking site content stats; use get_site_summary.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'update_site_settings', 'get_environment_status', 'get_site_summary' ),
			'notes'           => __( 'Requires manage_options. Values come directly from WordPress core options; third-party plugin settings are not included.', 'mcp-ai-wpoos' ),
		);
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
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
			'requires-capability',
		);
	}

	/**
	 * {@inheritdoc}
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
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to read site settings.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to read site settings.', 'mcp-ai-wpoos' ) );
		}

		$start_of_week = absint( get_option( 'start_of_week' ) );

		$week_days = array(
			__( 'Sunday', 'mcp-ai-wpoos' ),
			__( 'Monday', 'mcp-ai-wpoos' ),
			__( 'Tuesday', 'mcp-ai-wpoos' ),
			__( 'Wednesday', 'mcp-ai-wpoos' ),
			__( 'Thursday', 'mcp-ai-wpoos' ),
			__( 'Friday', 'mcp-ai-wpoos' ),
			__( 'Saturday', 'mcp-ai-wpoos' ),
		);

		$settings = array(
			'title'                  => esc_html( get_option( 'blogname' ) ),
			'description'            => esc_html( get_option( 'blogdescription' ) ),
			'url'                    => esc_url_raw( home_url( '/' ) ),
			'site_url'               => esc_url_raw( site_url( '/' ) ),
			'admin_email'            => sanitize_email( get_option( 'admin_email' ) ),
			'timezone'               => esc_html( (string) get_option( 'timezone_string', 'UTC' ) ),
			'gmt_offset'             => (float) get_option( 'gmt_offset' ),
			'date_format'            => esc_html( (string) get_option( 'date_format' ) ),
			'time_format'            => esc_html( (string) get_option( 'time_format' ) ),
			'start_of_week'          => $start_of_week,
			'start_of_week_label'    => isset( $week_days[ $start_of_week ] ) ? $week_days[ $start_of_week ] : '',
			'language'               => esc_html( (string) get_option( 'WPLANG', 'en_US' ) ),
			'posts_per_page'         => absint( get_option( 'posts_per_page' ) ),
			'default_category'       => absint( get_option( 'default_category' ) ),
			'default_comment_status' => esc_html( (string) get_option( 'default_comment_status' ) ),
			'default_ping_status'    => esc_html( (string) get_option( 'default_ping_status' ) ),
			'users_can_register'     => (bool) get_option( 'users_can_register' ),
			'current_time'           => esc_html( current_time( 'mysql' ) ),
		);

		return array(
			'message'  => __( 'Site settings retrieved.', 'mcp-ai-wpoos' ),
			'settings' => $settings,
		);
	}
}
