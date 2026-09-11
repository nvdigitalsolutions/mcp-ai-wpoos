<?php
/**
 * Cloudways Pro Toolkit Initialization (ecosystem port — Wave F2, cloudways
 * infra slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/cloudways/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*` with the `src/`
 *    root (the client/helpers resolve from `src/cloudways/`).
 * 3. Slimmed wiring — the admin settings page require is file-gated (the
 *    page lands with the cloudways infra slice; the instantiation guard
 *    stays byte-identical).
 * 4. Monolith guard — the helpers file declares the global
 *    `wp_mcp_ai_is_cloudways_toolkit_enabled()` function that the base copy
 *    also declares; the collision is a compile-time fatal, so the ENTIRE
 *    body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )` block
 *    (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
 *    `wp_mcp_ai_pro_register_cloudways_ecosystem_tools()` — both carry the full sixty-tool batch (they previously started with
 *    empty maps while the batch was in flight).
 *
 * @package NvoosContentGraphPro
 * @since   1.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Monolith guard (deviation 4): full-body runtime wrap — see the header.
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Load the API v2 client and helpers (always needed when toolkit is enabled).
	if ( ! class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/cloudways/class-wp-mcp-ai-cloudways-client.php';
	}

	// Register Cloudways disconnect admin-post handler.
	add_action(
		'admin_post_wp_mcp_ai_cloudways_disconnect',
		function () {
			if ( class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
				WP_MCP_AI_Cloudways_Client::instance()->handle_cloudways_disconnect();
			}
		}
	);
	if ( ! class_exists( 'WP_MCP_AI_Cloudways_Helpers' ) && ! function_exists( 'wp_mcp_ai_is_cloudways_toolkit_enabled' ) ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/cloudways/class-wp-mcp-ai-cloudways-helpers.php';
	}

	// Load the abstract tool base (required by all Cloudways tool classes).
	if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Base' ) ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-base.php';
	}

	// Load Cloudways admin settings page when in admin area.
	if ( is_admin() ) {
		$nvoos_content_graph_pro_cloudways_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cloudways-settings-page.php';
		if ( ! class_exists( 'WP_MCP_AI_Cloudways_Settings_Page' ) && file_exists( $nvoos_content_graph_pro_cloudways_settings ) ) {
			require_once $nvoos_content_graph_pro_cloudways_settings;
			new WP_MCP_AI_Cloudways_Settings_Page();
		}
	}

	/**
	 * Standalone-only tool filter — carries the ported cloudways tool subset
	 * (inert standalone, consumed by the base plugin monolith).
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_cloudways_tools( $tools ) {
		$nvoos_content_graph_pro_cloudways_tools = array(
			'WP_MCP_AI_Tool_Cloudways_Addon_Activate'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-addon-activate.php',
			'WP_MCP_AI_Tool_Cloudways_Addon_List'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-addon-list.php',
			'WP_MCP_AI_Tool_Cloudways_App_Clone'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-clone.php',
			'WP_MCP_AI_Tool_Cloudways_App_Clone_To_Server' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-clone-to-server.php',
			'WP_MCP_AI_Tool_Cloudways_App_Cname_Update'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-cname-update.php',
			'WP_MCP_AI_Tool_Cloudways_App_Cors_Headers_Update' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-cors-headers-update.php',
			'WP_MCP_AI_Tool_Cloudways_App_Create'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-create.php',
			'WP_MCP_AI_Tool_Cloudways_App_Credentials'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-credentials.php',
			'WP_MCP_AI_Tool_Cloudways_App_Cron_List_Get'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-cron-list-get.php',
			'WP_MCP_AI_Tool_Cloudways_App_Delete'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-delete.php',
			'WP_MCP_AI_Tool_Cloudways_App_Fpm_Settings_Get' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-fpm-settings-get.php',
			'WP_MCP_AI_Tool_Cloudways_App_Fpm_Settings_Update' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-fpm-settings-update.php',
			'WP_MCP_AI_Tool_Cloudways_App_Monitor_Summary' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-monitor-summary.php',
			'WP_MCP_AI_Tool_Cloudways_App_Mysql_Analytics' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-mysql-analytics.php',
			'WP_MCP_AI_Tool_Cloudways_App_Php_Analytics'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-php-analytics.php',
			'WP_MCP_AI_Tool_Cloudways_App_Restore'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-restore.php',
			'WP_MCP_AI_Tool_Cloudways_App_Restore_Rollback' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-restore-rollback.php',
			'WP_MCP_AI_Tool_Cloudways_App_Traffic_Analytics' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-traffic-analytics.php',
			'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Get' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-varnish-settings-get.php',
			'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Update' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-varnish-settings-update.php',
			'WP_MCP_AI_Tool_Cloudways_App_Vulnerabilities_List' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-vulnerabilities-list.php',
			'WP_MCP_AI_Tool_Cloudways_Cloudflare_Add_Domain' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-cloudflare-add-domain.php',
			'WP_MCP_AI_Tool_Cloudways_Cloudflare_Details'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-cloudflare-details.php',
			'WP_MCP_AI_Tool_Cloudways_Copilot_Insights_List' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-copilot-insights-list.php',
			'WP_MCP_AI_Tool_Cloudways_Create_App_Backup'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-create-app-backup.php',
			'WP_MCP_AI_Tool_Cloudways_Create_Server_Backup' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-create-server-backup.php',
			'WP_MCP_AI_Tool_Cloudways_Dns_Add_Record'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-dns-add-record.php',
			'WP_MCP_AI_Tool_Cloudways_Dns_Delete_Record'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-dns-delete-record.php',
			'WP_MCP_AI_Tool_Cloudways_Dns_List_Domains'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-dns-list-domains.php',
			'WP_MCP_AI_Tool_Cloudways_Dns_List_Records'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-dns-list-records.php',
			'WP_MCP_AI_Tool_Cloudways_Get_App'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-get-app.php',
			'WP_MCP_AI_Tool_Cloudways_Get_Operation_Status' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-get-operation-status.php',
			'WP_MCP_AI_Tool_Cloudways_Get_Server'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-get-server.php',
			'WP_MCP_AI_Tool_Cloudways_Git_Branches_Get'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-branches-get.php',
			'WP_MCP_AI_Tool_Cloudways_Git_Clone'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-clone.php',
			'WP_MCP_AI_Tool_Cloudways_Git_Generate_Key'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-generate-key.php',
			'WP_MCP_AI_Tool_Cloudways_Git_History_Get'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-history-get.php',
			'WP_MCP_AI_Tool_Cloudways_Git_Key_Get'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-key-get.php',
			'WP_MCP_AI_Tool_Cloudways_Git_Pull'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-pull.php',
			'WP_MCP_AI_Tool_Cloudways_List_Apps'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-list-apps.php',
			'WP_MCP_AI_Tool_Cloudways_List_Projects'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-list-projects.php',
			'WP_MCP_AI_Tool_Cloudways_List_Servers'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-list-servers.php',
			'WP_MCP_AI_Tool_Cloudways_Purge_App_Cache'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-purge-app-cache.php',
			'WP_MCP_AI_Tool_Cloudways_Restart_Service'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-restart-service.php',
			'WP_MCP_AI_Tool_Cloudways_Server_Clone'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-clone.php',
			'WP_MCP_AI_Tool_Cloudways_Server_Create'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-create.php',
			'WP_MCP_AI_Tool_Cloudways_Server_Delete'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-delete.php',
			'WP_MCP_AI_Tool_Cloudways_Server_Monitor_Summary' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-monitor-summary.php',
			'WP_MCP_AI_Tool_Cloudways_Server_Restart'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-restart.php',
			'WP_MCP_AI_Tool_Cloudways_Server_Scale'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-scale.php',
			'WP_MCP_AI_Tool_Cloudways_Server_Scale_Volume' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-scale-volume.php',
			'WP_MCP_AI_Tool_Cloudways_Server_Settings_Get' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-settings-get.php',
			'WP_MCP_AI_Tool_Cloudways_Server_Start'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-start.php',
			'WP_MCP_AI_Tool_Cloudways_Server_Stop'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-stop.php',
			'WP_MCP_AI_Tool_Cloudways_Service_Status'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-service-status.php',
			'WP_MCP_AI_Tool_Cloudways_Ssh_Key_Create'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-ssh-key-create.php',
			'WP_MCP_AI_Tool_Cloudways_Ssh_Key_Delete'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-ssh-key-delete.php',
			'WP_MCP_AI_Tool_Cloudways_Ssh_Key_List'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-ssh-key-list.php',
			'WP_MCP_AI_Tool_Cloudways_Update_App_Label'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-update-app-label.php',
			'WP_MCP_AI_Tool_Cloudways_Update_Server_Label' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-update-server-label.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_cloudways_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * cloudways tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the analytics/video inits). The list fills as the cloudways tool batch
	 * lands.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_cloudways_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Cloudways_Addon_Activate',
				'WP_MCP_AI_Tool_Cloudways_Addon_List',
				'WP_MCP_AI_Tool_Cloudways_App_Clone',
				'WP_MCP_AI_Tool_Cloudways_App_Clone_To_Server',
				'WP_MCP_AI_Tool_Cloudways_App_Cname_Update',
				'WP_MCP_AI_Tool_Cloudways_App_Cors_Headers_Update',
				'WP_MCP_AI_Tool_Cloudways_App_Create',
				'WP_MCP_AI_Tool_Cloudways_App_Credentials',
				'WP_MCP_AI_Tool_Cloudways_App_Cron_List_Get',
				'WP_MCP_AI_Tool_Cloudways_App_Delete',
				'WP_MCP_AI_Tool_Cloudways_App_Fpm_Settings_Get',
				'WP_MCP_AI_Tool_Cloudways_App_Fpm_Settings_Update',
				'WP_MCP_AI_Tool_Cloudways_App_Monitor_Summary',
				'WP_MCP_AI_Tool_Cloudways_App_Mysql_Analytics',
				'WP_MCP_AI_Tool_Cloudways_App_Php_Analytics',
				'WP_MCP_AI_Tool_Cloudways_App_Restore',
				'WP_MCP_AI_Tool_Cloudways_App_Restore_Rollback',
				'WP_MCP_AI_Tool_Cloudways_App_Traffic_Analytics',
				'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Get',
				'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Update',
				'WP_MCP_AI_Tool_Cloudways_App_Vulnerabilities_List',
				'WP_MCP_AI_Tool_Cloudways_Cloudflare_Add_Domain',
				'WP_MCP_AI_Tool_Cloudways_Cloudflare_Details',
				'WP_MCP_AI_Tool_Cloudways_Copilot_Insights_List',
				'WP_MCP_AI_Tool_Cloudways_Create_App_Backup',
				'WP_MCP_AI_Tool_Cloudways_Create_Server_Backup',
				'WP_MCP_AI_Tool_Cloudways_Dns_Add_Record',
				'WP_MCP_AI_Tool_Cloudways_Dns_Delete_Record',
				'WP_MCP_AI_Tool_Cloudways_Dns_List_Domains',
				'WP_MCP_AI_Tool_Cloudways_Dns_List_Records',
				'WP_MCP_AI_Tool_Cloudways_Get_App',
				'WP_MCP_AI_Tool_Cloudways_Get_Operation_Status',
				'WP_MCP_AI_Tool_Cloudways_Get_Server',
				'WP_MCP_AI_Tool_Cloudways_Git_Branches_Get',
				'WP_MCP_AI_Tool_Cloudways_Git_Clone',
				'WP_MCP_AI_Tool_Cloudways_Git_Generate_Key',
				'WP_MCP_AI_Tool_Cloudways_Git_History_Get',
				'WP_MCP_AI_Tool_Cloudways_Git_Key_Get',
				'WP_MCP_AI_Tool_Cloudways_Git_Pull',
				'WP_MCP_AI_Tool_Cloudways_List_Apps',
				'WP_MCP_AI_Tool_Cloudways_List_Projects',
				'WP_MCP_AI_Tool_Cloudways_List_Servers',
				'WP_MCP_AI_Tool_Cloudways_Purge_App_Cache',
				'WP_MCP_AI_Tool_Cloudways_Restart_Service',
				'WP_MCP_AI_Tool_Cloudways_Server_Clone',
				'WP_MCP_AI_Tool_Cloudways_Server_Create',
				'WP_MCP_AI_Tool_Cloudways_Server_Delete',
				'WP_MCP_AI_Tool_Cloudways_Server_Monitor_Summary',
				'WP_MCP_AI_Tool_Cloudways_Server_Restart',
				'WP_MCP_AI_Tool_Cloudways_Server_Scale',
				'WP_MCP_AI_Tool_Cloudways_Server_Scale_Volume',
				'WP_MCP_AI_Tool_Cloudways_Server_Settings_Get',
				'WP_MCP_AI_Tool_Cloudways_Server_Start',
				'WP_MCP_AI_Tool_Cloudways_Server_Stop',
				'WP_MCP_AI_Tool_Cloudways_Service_Status',
				'WP_MCP_AI_Tool_Cloudways_Ssh_Key_Create',
				'WP_MCP_AI_Tool_Cloudways_Ssh_Key_Delete',
				'WP_MCP_AI_Tool_Cloudways_Ssh_Key_List',
				'WP_MCP_AI_Tool_Cloudways_Update_App_Label',
				'WP_MCP_AI_Tool_Cloudways_Update_Server_Label',
			) as $nvoos_content_graph_pro_tool_class
		) {
			$nvoos_content_graph_pro_adapter = new WP_MCP_AI_Pro_Tool_Adapter( new $nvoos_content_graph_pro_tool_class() );
			try {
				$nvoos_content_graph_pro_parent_registry->register( $nvoos_content_graph_pro_adapter );
			} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
				unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
			}

			// Wrap into the nvoos/core registry so the agentic chat loop can
			// resolve and execute the tool (same path the AI addon uses).
			if ( class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
				$nvoos_content_graph_pro_core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
				try {
					$nvoos_content_graph_pro_core_tools->register( new \NvoosContentGraphAi\Adapter\GraphToolAdapter( $nvoos_content_graph_pro_adapter ) );
				} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
					unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
				}
			}
		}
	}

	// ---- Standalone-only tool wiring (deviation 5). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_cloudways_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_cloudways_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
