<?php
/**
 * Cloudways Toolkit Settings Page
 *
 * Management dashboard for the Cloudways Pro Toolkit.
 * Extends WP_MCP_AI_Toolkit_Settings_Base with tabs for
 * Overview, Servers & Apps, Configuration, Tools, and Help.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Cloudways_Toolkit
 * @since      1.1.15
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cloudways_Settings_Page' ) ) {

	/**
	 * Cloudways Toolkit settings page.
	 */
	class WP_MCP_AI_Cloudways_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->toolkit_slug = 'cloudways';
			$this->toolkit_name = __( 'Cloudways Hosting', 'mcp-ai-wpoos-pro' );
			$this->option_name  = 'wp_mcp_ai_cloudways_toolkit_settings';
			$this->page_slug    = 'nvoos-cloudways-toolkit';
			$this->icon         = 'dashicons-cloud';
			$this->has_research = false;

			parent::__construct();
		}

		/**
		 * Get toolkit slug.
		 *
		 * @return string
		 */
		protected function get_toolkit_slug() {
			return $this->toolkit_slug;
		}

		/**
		 * Get toolkit name.
		 *
		 * @return string
		 */
		protected function get_toolkit_name() {
			return $this->toolkit_name;
		}

		/**
		 * Get list of tools for this toolkit.
		 *
		 * @return array
		 */
		protected function get_tools_list() {
			return array(
				// Phase 1 - Read tools.
				'cloudways_list_servers'                => __( 'List Servers', 'mcp-ai-wpoos-pro' ),
				'cloudways_get_server'                  => __( 'Get Server Details', 'mcp-ai-wpoos-pro' ),
				'cloudways_list_apps'                   => __( 'List Applications', 'mcp-ai-wpoos-pro' ),
				'cloudways_get_app'                     => __( 'Get Application Details', 'mcp-ai-wpoos-pro' ),
				'cloudways_service_status'              => __( 'Service Status', 'mcp-ai-wpoos-pro' ),
				'cloudways_server_monitor_summary'      => __( 'Server Monitoring Summary', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_monitor_summary'         => __( 'App Monitoring Summary', 'mcp-ai-wpoos-pro' ),
				'cloudways_server_settings_get'         => __( 'Server Settings', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_traffic_analytics'       => __( 'App Traffic Analytics', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_php_analytics'           => __( 'App PHP Analytics', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_mysql_analytics'         => __( 'App MySQL Analytics', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_vulnerabilities_list'    => __( 'App Vulnerabilities', 'mcp-ai-wpoos-pro' ),
				'cloudways_list_projects'               => __( 'List Projects', 'mcp-ai-wpoos-pro' ),
				'cloudways_get_operation_status'        => __( 'Get Operation Status', 'mcp-ai-wpoos-pro' ),
				// Phase 2 - Safe actions.
				'cloudways_purge_app_cache'             => __( 'Purge App Cache', 'mcp-ai-wpoos-pro' ),
				'cloudways_restart_service'             => __( 'Restart Service', 'mcp-ai-wpoos-pro' ),
				'cloudways_create_app_backup'           => __( 'Create App Backup', 'mcp-ai-wpoos-pro' ),
				'cloudways_create_server_backup'        => __( 'Create Server Backup', 'mcp-ai-wpoos-pro' ),
				'cloudways_update_server_label'         => __( 'Update Server Label', 'mcp-ai-wpoos-pro' ),
				'cloudways_update_app_label'            => __( 'Update App Label', 'mcp-ai-wpoos-pro' ),
				'cloudways_git_pull'                    => __( 'Git Pull', 'mcp-ai-wpoos-pro' ),
				'cloudways_git_history_get'             => __( 'Git History', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_cron_list_get'           => __( 'App Cron List', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_credentials'             => __( 'App Credentials', 'mcp-ai-wpoos-pro' ),
				// Phase 3 - Provisioning & destructive.
				'cloudways_server_start'                => __( 'Start Server', 'mcp-ai-wpoos-pro' ),
				'cloudways_server_stop'                 => __( 'Stop Server', 'mcp-ai-wpoos-pro' ),
				'cloudways_server_restart'              => __( 'Restart Server', 'mcp-ai-wpoos-pro' ),
				'cloudways_server_scale'                => __( 'Scale Server', 'mcp-ai-wpoos-pro' ),
				'cloudways_server_clone'                => __( 'Clone Server', 'mcp-ai-wpoos-pro' ),
				'cloudways_server_create'               => __( 'Create Server', 'mcp-ai-wpoos-pro' ),
				'cloudways_server_delete'               => __( 'Delete Server', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_create'                  => __( 'Create App', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_clone'                   => __( 'Clone App', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_clone_to_server'         => __( 'Clone App to Server', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_delete'                  => __( 'Delete App', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_restore'                 => __( 'Restore App', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_restore_rollback'        => __( 'Rollback Restore', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_cname_update'            => __( 'Update App CNAME', 'mcp-ai-wpoos-pro' ),
				'cloudways_server_scale_volume'         => __( 'Scale Volume', 'mcp-ai-wpoos-pro' ),
				// Phase 4 - Add-ons, DNS, Cloudflare, SSH, Git, Copilot.
				'cloudways_addon_list'                  => __( 'List Add-ons', 'mcp-ai-wpoos-pro' ),
				'cloudways_addon_activate'              => __( 'Activate Add-on', 'mcp-ai-wpoos-pro' ),
				'cloudways_cloudflare_details'          => __( 'Cloudflare Details', 'mcp-ai-wpoos-pro' ),
				'cloudways_cloudflare_add_domain'       => __( 'Cloudflare Add Domain', 'mcp-ai-wpoos-pro' ),
				'cloudways_dns_list_domains'            => __( 'DNS List Domains', 'mcp-ai-wpoos-pro' ),
				'cloudways_dns_list_records'            => __( 'DNS List Records', 'mcp-ai-wpoos-pro' ),
				'cloudways_dns_add_record'              => __( 'DNS Add Record', 'mcp-ai-wpoos-pro' ),
				'cloudways_dns_delete_record'           => __( 'DNS Delete Record', 'mcp-ai-wpoos-pro' ),
				'cloudways_ssh_key_create'              => __( 'Create SSH Key', 'mcp-ai-wpoos-pro' ),
				'cloudways_ssh_key_delete'              => __( 'Delete SSH Key', 'mcp-ai-wpoos-pro' ),
				'cloudways_ssh_key_list'                => __( 'List SSH Keys', 'mcp-ai-wpoos-pro' ),
				'cloudways_git_generate_key'            => __( 'Generate Git Key', 'mcp-ai-wpoos-pro' ),
				'cloudways_git_key_get'                 => __( 'Get Git Key', 'mcp-ai-wpoos-pro' ),
				'cloudways_git_branches_get'            => __( 'Git Branches', 'mcp-ai-wpoos-pro' ),
				'cloudways_git_clone'                   => __( 'Git Clone', 'mcp-ai-wpoos-pro' ),
				'cloudways_copilot_insights_list'       => __( 'Copilot Insights', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_fpm_settings_get'        => __( 'App FPM Settings', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_fpm_settings_update'     => __( 'Update FPM Settings', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_varnish_settings_get'    => __( 'App Varnish Settings', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_varnish_settings_update' => __( 'Update Varnish Settings', 'mcp-ai-wpoos-pro' ),
				'cloudways_app_cors_headers_update'     => __( 'Update CORS Headers', 'mcp-ai-wpoos-pro' ),
			);
		}

		/**
		 * Render overview tab.
		 */
		protected function render_overview_tab() {
			$settings  = WP_MCP_AI_Admin_Settings::get_settings();
			$connected = ! empty( $settings['cloudways_connected'] );
			$email     = isset( $settings['cloudways_email'] ) ? $settings['cloudways_email'] : '';
			$account   = isset( $settings['cloudways_account_name'] ) ? $settings['cloudways_account_name'] : '';
			$conn_time = isset( $settings['cloudways_connection_time'] ) ? absint( $settings['cloudways_connection_time'] ) : 0;
			?>
			<div class="cloudways-overview">
				<h2><?php esc_html_e( 'Cloudways Connection Status', 'mcp-ai-wpoos-pro' ); ?></h2>

				<table class="widefat fixed striped" style="max-width: 700px;">
					<tbody>
						<tr>
							<th style="width: 200px;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<?php if ( $connected ) : ?>
									<span style="color: #46b450;" class="dashicons dashicons-yes-alt"></span>
									<strong><?php esc_html_e( 'Connected', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php else : ?>
									<span style="color: #dc3232;" class="dashicons dashicons-no-alt"></span>
									<strong><?php esc_html_e( 'Not Connected', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( $email ) : ?>
						<tr>
							<th><?php esc_html_e( 'Account Email', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( $email ); ?></td>
						</tr>
						<?php endif; ?>
						<?php if ( $account ) : ?>
						<tr>
							<th><?php esc_html_e( 'Account', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( $account ); ?></td>
						</tr>
						<?php endif; ?>
						<?php if ( $conn_time ) : ?>
						<tr>
							<th><?php esc_html_e( 'Last Connected', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $conn_time ) ); ?> UTC</td>
						</tr>
						<?php endif; ?>
						<tr>
							<th><?php esc_html_e( 'API Version', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>v2</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Total Tools', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( count( $this->get_tools_list() ) ); ?></td>
						</tr>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<?php if ( $connected ) : ?>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wp_mcp_ai_cloudways_test_connection' ), 'wp_mcp_ai_cloudways_test' ) ); ?>" class="button">
							<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					<?php else : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=cloudways' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Configure Cloudways Credentials', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( 'https://developers.cloudways.com/' ); ?>" class="button" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Cloudways API Docs', 'mcp-ai-wpoos-pro' ); ?>
						<span class="dashicons dashicons-external" style="vertical-align: middle;"></span>
					</a>
				</p>
			</div>
			<?php
		}

		/**
		 * Render configuration tab.
		 */
		protected function render_configuration_tab() {
			?>
			<div class="cloudways-configuration">
				<h2><?php esc_html_e( 'Cloudways API Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php esc_html_e( 'Cloudways credentials are managed in the main plugin settings under Settings → Tools → Connections.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=cloudways' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Open Connection Settings', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( 'https://platform.cloudways.com/api' ); ?>" class="button" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Generate API Key', 'mcp-ai-wpoos-pro' ); ?>
						<span class="dashicons dashicons-external" style="vertical-align: middle;"></span>
					</a>
				</p>
			</div>
			<?php
		}

		/**
		 * Sanitize settings.
		 *
		 * @param array $input Raw input.
		 * @return array Sanitized input.
		 */
		public function sanitize_settings( $input ) {
			if ( ! is_array( $input ) ) {
				return array();
			}

			$sanitized = array();

			if ( isset( $input['enable_remote_sites'] ) ) {
				$sanitized['enable_remote_sites'] = (bool) $input['enable_remote_sites'];
			}

			return $sanitized;
		}
	}
}
