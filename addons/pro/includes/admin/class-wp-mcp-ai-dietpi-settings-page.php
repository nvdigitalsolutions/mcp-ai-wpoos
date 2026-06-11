<?php
/**
 * DietPi Toolkit Settings Page
 *
 * Management dashboard for the DietPi Pro Toolkit.
 * Extends WP_MCP_AI_Toolkit_Settings_Base with tabs for
 * Overview, Configuration, Tools, and Help.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_DietPi_Settings_Page' ) ) {

	/**
	 * DietPi Toolkit settings page.
	 */
	class WP_MCP_AI_DietPi_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->toolkit_slug = 'dietpi';
			$this->toolkit_name = __( 'DietPi', 'mcp-ai-wpoos-pro' );
			$this->option_name  = 'wp_mcp_ai_dietpi_settings';
			$this->page_slug    = 'nvoos-dietpi-toolkit';
			$this->icon         = 'dashicons-dashboard';
			$this->has_research = false;

			parent::__construct();
		}

		/** {@inheritdoc} */
		protected function get_toolkit_slug() { return $this->toolkit_slug; }

		/** {@inheritdoc} */
		protected function get_toolkit_name() { return $this->toolkit_name; }

		/** {@inheritdoc} */
		protected function get_tools_list() {
			return array(
				'dietpi_send_ssh_command'      => __( 'Send SSH Command', 'mcp-ai-wpoos-pro' ),
				'dietpi_list_services'         => __( 'List Services', 'mcp-ai-wpoos-pro' ),
				'dietpi_control_service'       => __( 'Control Service', 'mcp-ai-wpoos-pro' ),
				'dietpi_system_info'           => __( 'System Info', 'mcp-ai-wpoos-pro' ),
				'dietpi_system_stats'          => __( 'System Stats', 'mcp-ai-wpoos-pro' ),
				'dietpi_list_transmission'     => __( 'List Transmission Torrents', 'mcp-ai-wpoos-pro' ),
				'dietpi_add_transmission'      => __( 'Add Transmission Torrent', 'mcp-ai-wpoos-pro' ),
				'dietpi_control_transmission'  => __( 'Control Transmission', 'mcp-ai-wpoos-pro' ),
				'dietpi_search_jackett'        => __( 'Search Jackett', 'mcp-ai-wpoos-pro' ),
				'dietpi_list_jackett_indexers' => __( 'List Jackett Indexers', 'mcp-ai-wpoos-pro' ),
				'dietpi_list_sonarr_series'    => __( 'List Sonarr Series', 'mcp-ai-wpoos-pro' ),
				'dietpi_add_sonarr_series'     => __( 'Add Sonarr Series', 'mcp-ai-wpoos-pro' ),
				'dietpi_manage_sonarr'         => __( 'Manage Sonarr', 'mcp-ai-wpoos-pro' ),
				'dietpi_list_radarr_movies'    => __( 'List Radarr Movies', 'mcp-ai-wpoos-pro' ),
				'dietpi_add_radarr_movie'      => __( 'Add Radarr Movie', 'mcp-ai-wpoos-pro' ),
				'dietpi_manage_radarr'         => __( 'Manage Radarr', 'mcp-ai-wpoos-pro' ),
				'dietpi_media_center'          => __( 'Media Center', 'mcp-ai-wpoos-pro' ),
				'dietpi_health_check'          => __( 'Health Check', 'mcp-ai-wpoos-pro' ),
				'dietpi_media_request_flow'    => __( 'Media Request Flow', 'mcp-ai-wpoos-pro' ),
			);
		}

		/** {@inheritdoc} */
		protected function render_overview_tab() {
			$settings = wp_mcp_ai_dietpi_get_settings();
			$has_ssh  = wp_mcp_ai_dietpi_has_ssh_credentials();
			$host     = isset( $settings['host'] ) ? $settings['host'] : '';
			$user     = isset( $settings['ssh_user'] ) ? $settings['ssh_user'] : 'root';
			?>
			<div class="dietpi-overview">
				<h2><?php esc_html_e( 'DietPi Connection Status', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="widefat fixed striped" style="max-width:700px;">
					<tbody>
						<tr>
							<th style="width:200px;"><?php esc_html_e( 'SSH Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<?php if ( $has_ssh ) : ?>
									<span style="color:#46b450;" class="dashicons dashicons-yes-alt"></span>
									<strong><?php esc_html_e( 'Configured', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php else : ?>
									<span style="color:#dc3232;" class="dashicons dashicons-no-alt"></span>
									<strong><?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( '' !== $host ) : ?>
						<tr><th><?php esc_html_e( 'Host', 'mcp-ai-wpoos-pro' ); ?></th><td><?php echo esc_html( $host . ' (' . $user . ')' ); ?></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Total Tools', 'mcp-ai-wpoos-pro' ); ?></th><td><?php echo count( $this->get_tools_list() ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Managed Apps', 'mcp-ai-wpoos-pro' ); ?></th><td>Transmission, Jackett, Sonarr, Radarr, Plex, Jellyfin</td></tr>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=features' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Enable / Disable Toolkit', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		/** {@inheritdoc} */
		protected function render_configuration_tab() {
			$settings = wp_mcp_ai_dietpi_get_settings();
			$apps     = array( 'transmission', 'jackett', 'sonarr', 'radarr', 'plex', 'jellyfin' );
			?>
			<div class="dietpi-configuration">
				<h2><?php esc_html_e( 'SSH Connection', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e( 'Pi Hostname / IP', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[host]" value="<?php echo esc_attr( $settings['host'] ); ?>" class="regular-text" placeholder="192.168.1.100 or dietpi.local" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'SSH Port', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[ssh_port]" value="<?php echo esc_attr( $settings['ssh_port'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'SSH User', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[ssh_user]" value="<?php echo esc_attr( $settings['ssh_user'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Auth Method', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( $this->option_name ); ?>[ssh_auth_method]">
								<option value="key" <?php selected( $settings['ssh_auth_method'], 'key' ); ?>><?php esc_html_e( 'SSH Key (recommended)', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="password" <?php selected( $settings['ssh_auth_method'], 'password' ); ?>><?php esc_html_e( 'Password', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
						</td>
					</tr>
					<tr id="row-ssh-key">
						<th><label><?php esc_html_e( 'SSH Private Key (PEM)', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><textarea name="<?php echo esc_attr( $this->option_name ); ?>[ssh_private_key]" class="large-text code" rows="6" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"><?php echo esc_textarea( '' !== $settings['ssh_private_key'] && false !== strpos( $settings['ssh_private_key'], '-----BEGIN' ) ? $settings['ssh_private_key'] : '' ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Paste your Ed25519 or RSA private key, or enter a filesystem path to the key file.', 'mcp-ai-wpoos-pro' ); ?></p></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Key Passphrase', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><input type="password" name="<?php echo esc_attr( $this->option_name ); ?>[ssh_key_passphrase]" value="" class="regular-text" autocomplete="new-password" /><p class="description"><?php esc_html_e( 'Leave blank if your key has no passphrase.', 'mcp-ai-wpoos-pro' ); ?></p></td>
					</tr>
					<tr id="row-ssh-password" style="display:none;">
						<th><label><?php esc_html_e( 'SSH Password', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><input type="password" name="<?php echo esc_attr( $this->option_name ); ?>[ssh_password]" value="" class="regular-text" autocomplete="new-password" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Managed Applications', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure API access for each app running on your DietPi device. API keys can be found in each app\'s Settings → General page.', 'mcp-ai-wpoos-pro' ); ?></p>

				<?php foreach ( $apps as $app ) : $cfg = isset( $settings['apps'][ $app ] ) ? $settings['apps'][ $app ] : array(); $name = ucfirst( $app ); ?>
				<div class="toolkit-card" style="margin-bottom:12px;">
					<h3 style="margin-top:0;"><?php echo esc_html( $name ); ?></h3>
					<table class="form-table">
						<tr>
							<th style="width:120px;"><label><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td><input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[apps][<?php echo esc_attr( $app ); ?>][enabled]" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?> /></td>
						</tr>
						<tr>
							<th><label><?php esc_html_e( 'Base URL', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td><input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[apps][<?php echo esc_attr( $app ); ?>][url]" value="<?php echo esc_attr( isset( $cfg['url'] ) ? $cfg['url'] : '' ); ?>" class="regular-text" placeholder="http://192.168.1.100:<?php echo esc_attr( 'plex' === $app ? '32400' : ( 'jellyfin' === $app ? '8096' : ( 'radarr' === $app ? '7878' : ( 'sonarr' === $app ? '8989' : ( 'jackett' === $app ? '9117' : '9091' ) ) ) ) ); ?>" /></td>
						</tr>
						<?php if ( 'transmission' === $app ) : ?>
						<tr><th><label><?php esc_html_e( 'Username', 'mcp-ai-wpoos-pro' ); ?></label></th><td><input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[apps][<?php echo esc_attr( $app ); ?>][username]" value="<?php echo esc_attr( isset( $cfg['username'] ) ? $cfg['username'] : '' ); ?>" class="regular-text" /></td></tr>
						<tr><th><label><?php esc_html_e( 'Password', 'mcp-ai-wpoos-pro' ); ?></label></th><td><input type="password" name="<?php echo esc_attr( $this->option_name ); ?>[apps][<?php echo esc_attr( $app ); ?>][password]" value="" class="regular-text" autocomplete="new-password" /></td></tr>
						<?php elseif ( 'plex' === $app ) : ?>
						<tr><th><label><?php esc_html_e( 'Plex Token', 'mcp-ai-wpoos-pro' ); ?></label></th><td><input type="password" name="<?php echo esc_attr( $this->option_name ); ?>[apps][<?php echo esc_attr( $app ); ?>][token]" value="" class="regular-text" autocomplete="new-password" /></td></tr>
						<?php else : ?>
						<tr><th><label><?php esc_html_e( 'API Key', 'mcp-ai-wpoos-pro' ); ?></label></th><td><input type="password" name="<?php echo esc_attr( $this->option_name ); ?>[apps][<?php echo esc_attr( $app ); ?>][api_key]" value="" class="regular-text" autocomplete="new-password" /></td></tr>
						<?php endif; ?>
					</table>
				</div>
				<?php endforeach; ?>
			</div>
			<?php
		}

		/** {@inheritdoc} */
		public function sanitize_settings( $input ) {
			if ( ! is_array( $input ) ) { return array(); }
			$sanitized = array();
			$sanitized['host']                = sanitize_text_field( isset( $input['host'] ) ? $input['host'] : '' );
			$sanitized['ssh_port']            = absint( isset( $input['ssh_port'] ) ? $input['ssh_port'] : 22 );
			$sanitized['ssh_user']            = sanitize_text_field( isset( $input['ssh_user'] ) ? $input['ssh_user'] : 'root' );
			$sanitized['ssh_auth_method']     = in_array( isset( $input['ssh_auth_method'] ) ? $input['ssh_auth_method'] : 'key', array( 'key', 'password' ), true ) ? $input['ssh_auth_method'] : 'key';
			// Only update key if a new one is provided.
			$current = get_option( $this->option_name, array() );
			$new_key = isset( $input['ssh_private_key'] ) ? trim( $input['ssh_private_key'] ) : '';
			if ( '' !== $new_key && false !== strpos( $new_key, '-----BEGIN' ) ) {
				$sanitized['ssh_private_key'] = $new_key; // Store inline PEM as-is (it's text, not executable).
			} elseif ( '' !== $new_key && file_exists( $new_key ) ) {
				$sanitized['ssh_private_key'] = $new_key; // It's a file path.
			} else {
				$sanitized['ssh_private_key'] = isset( $current['ssh_private_key'] ) ? $current['ssh_private_key'] : '';
			}
			// Only update passphrase/password if provided.
			if ( ! empty( $input['ssh_key_passphrase'] ) ) { $sanitized['ssh_key_passphrase'] = $input['ssh_key_passphrase']; } else { $sanitized['ssh_key_passphrase'] = isset( $current['ssh_key_passphrase'] ) ? $current['ssh_key_passphrase'] : ''; }
			if ( ! empty( $input['ssh_password'] ) ) { $sanitized['ssh_password'] = $input['ssh_password']; } else { $sanitized['ssh_password'] = isset( $current['ssh_password'] ) ? $current['ssh_password'] : ''; }
			// App settings.
			$apps = array( 'transmission', 'jackett', 'sonarr', 'radarr', 'plex', 'jellyfin' );
			$sanitized['apps'] = array();
			foreach ( $apps as $app ) {
				$app_input = isset( $input['apps'][ $app ] ) ? $input['apps'][ $app ] : array();
				$sanitized['apps'][ $app ] = array(
					'enabled'  => ! empty( $app_input['enabled'] ),
					'url'      => isset( $app_input['url'] ) ? esc_url_raw( $app_input['url'] ) : '',
				);
				if ( 'transmission' === $app ) {
					$sanitized['apps'][ $app ]['username'] = isset( $app_input['username'] ) ? sanitize_text_field( $app_input['username'] ) : '';
					if ( ! empty( $app_input['password'] ) ) { $sanitized['apps'][ $app ]['password'] = $app_input['password']; } else { $sanitized['apps'][ $app ]['password'] = isset( $current['apps'][ $app ]['password'] ) ? $current['apps'][ $app ]['password'] : ''; }
				} elseif ( 'plex' === $app ) {
					if ( ! empty( $app_input['token'] ) ) { $sanitized['apps'][ $app ]['token'] = $app_input['token']; } else { $sanitized['apps'][ $app ]['token'] = isset( $current['apps'][ $app ]['token'] ) ? $current['apps'][ $app ]['token'] : ''; }
				} else {
					if ( ! empty( $app_input['api_key'] ) ) { $sanitized['apps'][ $app ]['api_key'] = $app_input['api_key']; } else { $sanitized['apps'][ $app ]['api_key'] = isset( $current['apps'][ $app ]['api_key'] ) ? $current['apps'][ $app ]['api_key'] : ''; }
				}
			}
			return $sanitized;
		}
	}
}
