<?php
/**
 * Cloudways Toolkit MCP Server
 *
 * Phase 6 Tier-2 promotion. Exposes Cloudways hosting management tools
 * via the per-toolkit MCP JSON-RPC endpoint.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudways MCP server.
 *
 * Exposes Cloudways hosting management tools: server management,
 * application management, monitoring, backups, DNS, SSH, Git, and more.
 */
class WP_MCP_AI_Cloudways_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'cloudways';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Cloudways Hosting', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Cloudways hosting management — server provisioning, application management, monitoring, backups, DNS, SSH keys, Git integration, and performance analytics.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array();
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the Cloudways MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_cloudways_candidate_tools',
			array(
				// Phase 1 - Read tools.
				'cloudways_list_servers',
				'cloudways_get_server',
				'cloudways_list_apps',
				'cloudways_get_app',
				'cloudways_service_status',
				'cloudways_server_monitor_summary',
				'cloudways_app_monitor_summary',
				'cloudways_server_settings_get',
				'cloudways_app_traffic_analytics',
				'cloudways_app_php_analytics',
				'cloudways_app_mysql_analytics',
				'cloudways_app_vulnerabilities_list',
				'cloudways_list_projects',
				'cloudways_get_operation_status',
				// Phase 2 - Safe actions.
				'cloudways_purge_app_cache',
				'cloudways_restart_service',
				'cloudways_create_app_backup',
				'cloudways_create_server_backup',
				'cloudways_update_server_label',
				'cloudways_update_app_label',
				'cloudways_git_pull',
				'cloudways_git_history_get',
				'cloudways_app_cron_list_get',
				'cloudways_app_credentials',
				// Phase 3 - Provisioning & destructive.
				'cloudways_server_start',
				'cloudways_server_stop',
				'cloudways_server_restart',
				'cloudways_server_scale',
				'cloudways_server_clone',
				'cloudways_server_create',
				'cloudways_server_delete',
				'cloudways_app_create',
				'cloudways_app_clone',
				'cloudways_app_clone_to_server',
				'cloudways_app_delete',
				'cloudways_app_restore',
				'cloudways_app_restore_rollback',
				'cloudways_app_cname_update',
				'cloudways_server_scale_volume',
				// Phase 4 - Add-ons, DNS, Cloudflare, SSH, Git, Copilot.
				'cloudways_addon_list',
				'cloudways_addon_activate',
				'cloudways_cloudflare_details',
				'cloudways_cloudflare_add_domain',
				'cloudways_dns_list_domains',
				'cloudways_dns_list_records',
				'cloudways_dns_add_record',
				'cloudways_dns_delete_record',
				'cloudways_ssh_key_create',
				'cloudways_ssh_key_delete',
				'cloudways_ssh_key_list',
				'cloudways_git_generate_key',
				'cloudways_git_key_get',
				'cloudways_git_branches_get',
				'cloudways_git_clone',
				'cloudways_copilot_insights_list',
				'cloudways_app_fpm_settings_get',
				'cloudways_app_fpm_settings_update',
				'cloudways_app_varnish_settings_get',
				'cloudways_app_varnish_settings_update',
				'cloudways_app_cors_headers_update',
			)
		);
	}
}
