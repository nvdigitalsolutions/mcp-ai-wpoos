<?php
/**
 * Phase 7 — Dedicated Pro Admin UI for Toolkit MCP Servers.
 *
 * Registers a standalone admin page (`nvoos-pro-toolkit-mcp-servers`) under the
 * NV oOS Pro Dashboard menu.  Five tabs:
 *   1. Servers    — WP_List_Table over the server registry.
 *   2. Detail     — per-server accordions (Overview · Tools · Credentials · Limits · Audit).
 *   3. Audit Log  — cross-mount audit pulled from /mcp-ai-pro/v1/mcp-audit.
 *   4. Discovery  — pretty-printed /.well-known/mcp document.
 *   5. Help       — slash-command ↔ WP-CLI equivalents, hooks reference.
 *
 * JS stack: vanilla + wp.apiFetch (no build step required).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page' ) ) {

	/**
	 * Standalone admin page for Toolkit MCP Server management.
	 *
	 * @since 1.6.0
	 */
	class WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page {

		/**
		 * Admin page slug.
		 *
		 * @since 1.6.0
		 * @var string
		 */
		const PAGE_SLUG = 'nvoos-pro-toolkit-mcp-servers';

		/**
		 * Nonce action for the enable/disable toggle.
		 *
		 * @since 1.6.0
		 * @var string
		 */
		const TOGGLE_NONCE = 'wp_mcp_ai_pro_mcp_servers_toggle';

		/**
		 * Nonce action for the per-server limits form.
		 * Appended with _{slug} per server.
		 *
		 * @since 1.6.0
		 * @var string
		 */
		const LIMITS_NONCE_PREFIX = 'wp_mcp_ai_pro_mcp_servers_limits_';

		/**
		 * Nonce action for clearing the audit log.
		 *
		 * @since 1.6.0
		 * @var string
		 */
		const CLEAR_AUDIT_NONCE = 'wp_mcp_ai_pro_mcp_servers_clear_audit';

		/**
		 * Asset version fallback when WP_MCP_AI_VERSION is not defined.
		 *
		 * @since 1.6.0
		 * @var string
		 */
		const ASSET_VERSION_FALLBACK = '1.0';

		/**
		 * WordPress hook name returned by add_submenu_page().
		 *
		 * @since 1.6.0
		 * @var string
		 */
		private $page_hook = '';

		/**
		 * Valid tabs.
		 *
		 * @since 1.6.0
		 * @var string[]
		 */
		private $tabs = array( 'servers', 'detail', 'audit', 'discovery', 'help' );

		/**
		 * Constructor — bind WordPress hooks.
		 *
		 * @since 1.6.0
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_page' ), 26 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'admin_post_wp_mcp_ai_toggle_toolkit_mcp_server', array( $this, 'handle_toggle' ) );
					add_action( 'admin_post_wp_mcp_ai_save_toolkit_mcp_limits', array( $this, 'handle_limits_save' ) );
					add_action( 'admin_post_wp_mcp_ai_clear_toolkit_mcp_audit', array( $this, 'handle_clear_audit' ) );
					add_action( 'admin_post_wp_mcp_ai_revoke_oauth_token', array( $this, 'handle_revoke_oauth_token' ) );
		}

		// ------------------------------------------------------------------ //
		// Registration
		// ------------------------------------------------------------------ //

		/**
		 * Register the submenu page under nvoos-pro-dashboard.
		 *
		 * @since 1.6.0
		 * @return void
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'nvoos-pro-dashboard',
				__( 'Toolkit MCP Servers', 'mcp-ai-wpoos-pro' ),
				__( 'MCP Servers', 'mcp-ai-wpoos-pro' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue page-scoped styles and scripts.
		 *
		 * @since 1.6.0
		 *
		 * @param string $hook Current admin page hook.
		 * @return void
		 */
		public function enqueue_assets( $hook ) {
			$is_page = ! empty( $this->page_hook ) && $hook === $this->page_hook;
			if ( ! $is_page ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page slug for script enqueue only.
				$is_page = isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ) );
			}
			if ( ! $is_page ) {
				return;
			}

			$base_url = defined( 'WP_MCP_AI_URL' ) ? WP_MCP_AI_URL : plugin_dir_url( __FILE__ );

			wp_enqueue_style(
				'wp-mcp-ai-pro-toolkit-mcp-servers',
				$base_url . 'assets/css/pro-toolkit-mcp-servers.css',
				array(),
				defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : self::ASSET_VERSION_FALLBACK
			);

			wp_enqueue_script(
				'wp-mcp-ai-pro-toolkit-mcp-servers',
				$base_url . 'assets/js/pro-toolkit-mcp-servers.js',
				array( 'wp-api-fetch', 'wp-i18n' ),
				defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : self::ASSET_VERSION_FALLBACK,
				true
			);

			wp_localize_script(
				'wp-mcp-ai-pro-toolkit-mcp-servers',
				'wpMcpAiProMcpServers',
				array(
					'apiBase'      => rest_url( 'mcp-ai-pro/v1' ),
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					'toggleNonce'  => wp_create_nonce( self::TOGGLE_NONCE ),
					'adminPostUrl' => admin_url( 'admin-post.php' ),
					'pageUrl'      => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
					'wellKnownUrl' => home_url( '/.well-known/mcp' ),
					'i18n'         => array(
						'confirmDisable'  => __( 'Disable this server? Clients connected to it will lose access.', 'mcp-ai-wpoos-pro' ),
						'confirmRevoke'   => __( 'Revoke this token? This cannot be undone.', 'mcp-ai-wpoos-pro' ),
						'confirmClearLog' => __( 'Clear the entire audit log? This cannot be undone.', 'mcp-ai-wpoos-pro' ),
						'tokenSavePrompt' => __( "Copy and store this token — it won't be shown again.", 'mcp-ai-wpoos-pro' ),
						'tokenCopied'     => __( 'Copied!', 'mcp-ai-wpoos-pro' ),
						'generating'      => __( 'Generating…', 'mcp-ai-wpoos-pro' ),
						'revoking'        => __( 'Revoking…', 'mcp-ai-wpoos-pro' ),
						'loading'         => __( 'Loading…', 'mcp-ai-wpoos-pro' ),
					),
				)
			);
		}

		// ------------------------------------------------------------------ //
		// Admin-post handlers
		// ------------------------------------------------------------------ //

		/**
		 * Handle enable / disable toggle (admin-post fallback for no-JS environments).
		 *
		 * @since 1.6.0
		 * @return void
		 */
		public function handle_toggle() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ), 403 );
			}

			check_admin_referer( self::TOGGLE_NONCE );

			$slug   = isset( $_POST['server_slug'] ) ? sanitize_key( wp_unslash( $_POST['server_slug'] ) ) : '';
			$enable = ! empty( $_POST['enable'] );

			if ( '' !== $slug ) {
				update_option(
					WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . $slug,
					array_merge(
						$this->get_server_config( $slug ),
						array( 'enabled' => $enable )
					)
				);

				/**
				 * Fires after an admin toggles a toolkit MCP server on/off.
				 *
				 * @since 1.6.0
				 *
				 * @param string $slug    Server slug.
				 * @param bool   $enabled New enabled state.
				 * @param int    $user_id Current user ID.
				 */
				do_action( 'wp_mcp_ai_toolkit_mcp_server_toggled', $slug, $enable, get_current_user_id() );
			}

			$redirect = add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'tab'     => 'servers',
					'toggled' => rawurlencode( $slug ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		/**
		 * Handle per-server limits save.
		 *
		 * @since 1.6.0
		 * @return void
		 */
		public function handle_limits_save() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ), 403 );
			}

			$slug = isset( $_POST['server_slug'] ) ? sanitize_key( wp_unslash( $_POST['server_slug'] ) ) : '';
			check_admin_referer( self::LIMITS_NONCE_PREFIX . $slug );

			if ( '' !== $slug ) {
				$config  = $this->get_server_config( $slug );
				$updated = array_merge(
					$config,
					array(
						'requests_per_minute' => isset( $_POST['requests_per_minute'] ) ? max( 0, absint( wp_unslash( $_POST['requests_per_minute'] ) ) ) : 0,
						'max_payload_bytes'   => isset( $_POST['max_payload_bytes'] ) ? max( 0, absint( wp_unslash( $_POST['max_payload_bytes'] ) ) ) : 0,
						'max_iterations'      => isset( $_POST['max_iterations'] ) ? max( 0, absint( wp_unslash( $_POST['max_iterations'] ) ) ) : 0,
					)
				);
				update_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . $slug, $updated );
			}

			$redirect = add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'tab'          => 'detail',
					'server'       => rawurlencode( $slug ),
					'limits_saved' => '1',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		/**
		 * Handle audit log clear.
		 *
		 * @since 1.6.0
		 * @return void
		 */
		public function handle_clear_audit() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ), 403 );
			}

			check_admin_referer( self::CLEAR_AUDIT_NONCE );

			if ( class_exists( 'WP_MCP_AI_Toolkit_MCP_Audit_Log' ) ) {
				delete_option( 'wp_mcp_ai_toolkit_mcp_audit_log' );
				WP_MCP_AI_Toolkit_MCP_Audit_Log::reset_instance();
			}

			$redirect = add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'tab'           => 'audit',
					'audit_cleared' => '1',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
					exit;
		}

				/**
				 * Handle OAuth token revocation.
				 *
				 * @since 1.7.0
				 * @return void
				 */
		public function handle_revoke_oauth_token() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ), 403 );
			}

			check_admin_referer( 'wp_mcp_ai_revoke_oauth_token' );

			$token_key   = isset( $_POST['token_key'] ) ? sanitize_text_field( wp_unslash( $_POST['token_key'] ) ) : '';
			$server_slug = isset( $_POST['server_slug'] ) ? sanitize_key( wp_unslash( $_POST['server_slug'] ) ) : '';

			if ( '' !== $token_key && class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
				WP_MCP_AI_OAuth_Server::get_instance()->revoke_token( $token_key );
			}

			$redirect = add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'tab'           => 'detail',
					'server'        => rawurlencode( $server_slug ),
					'oauth_revoked' => '1',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

				// ------------------------------------------------------------------ //
				// Page render
		// ------------------------------------------------------------------ //

		/**
		 * Render the full page.
		 *
		 * @since 1.6.0
		 * @return void
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos-pro' ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading tab for display purposes only.
			$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'servers';
			if ( ! in_array( $current_tab, $this->tabs, true ) ) {
				$current_tab = 'servers';
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading server slug for display purposes only.
			$active_server = isset( $_GET['server'] ) ? sanitize_key( wp_unslash( $_GET['server'] ) ) : '';
			?>
			<div class="wrap wp-mcp-ai-pro-mcp-page">
				<h1>
					<span class="dashicons dashicons-rest-api" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:8px;color:#2271b1;"></span>
					<?php esc_html_e( 'Toolkit MCP Servers', 'mcp-ai-wpoos-pro' ); ?>
					<span style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:4px 12px;border-radius:12px;font-size:11px;font-weight:600;margin-left:10px;text-transform:uppercase;letter-spacing:.5px;">PRO</span>
				</h1>

				<?php $this->render_notices(); ?>

				<nav class="nav-tab-wrapper wp-clearfix">
					<?php
					$tab_labels = array(
						'servers'   => __( 'Servers', 'mcp-ai-wpoos-pro' ),
						'detail'    => __( 'Server Detail', 'mcp-ai-wpoos-pro' ),
						'audit'     => __( 'Audit Log', 'mcp-ai-wpoos-pro' ),
						'discovery' => __( 'Discovery', 'mcp-ai-wpoos-pro' ),
						'help'      => __( 'Help', 'mcp-ai-wpoos-pro' ),
					);
					foreach ( $tab_labels as $tab_slug => $tab_label ) {
						$url        = add_query_arg(
							array(
								'page' => self::PAGE_SLUG,
								'tab'  => $tab_slug,
							),
							admin_url( 'admin.php' )
						);
						$is_current = $current_tab === $tab_slug;
						printf(
							'<a href="%s" class="nav-tab%s">%s</a>',
							esc_url( $url ),
							$is_current ? ' nav-tab-active' : '',
							esc_html( $tab_label )
						);
					}
					?>
				</nav>

				<div class="wp-mcp-ai-pro-mcp-tab-content">
					<?php
					switch ( $current_tab ) {
						case 'servers':
							$this->render_tab_servers();
							break;
						case 'detail':
							$this->render_tab_detail( $active_server );
							break;
						case 'audit':
							$this->render_tab_audit();
							break;
						case 'discovery':
							$this->render_tab_discovery();
							break;
						case 'help':
							$this->render_tab_help();
							break;
					}
					?>
				</div>
			</div>
			<?php
		}

		// ------------------------------------------------------------------ //
		// Notices
		// ------------------------------------------------------------------ //

		/**
		 * Render admin notices from query-string flags.
		 *
		 * @since 1.6.0
		 * @return void
		 */
		private function render_notices() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading display flags only.
			if ( ! empty( $_GET['toggled'] ) ) {
				$slug = sanitize_key( wp_unslash( $_GET['toggled'] ) );
				echo '<div class="notice notice-success is-dismissible"><p>' .
					sprintf(
						/* translators: %s: server slug */
						esc_html__( 'Server "%s" updated.', 'mcp-ai-wpoos-pro' ),
						esc_html( $slug )
					) . '</p></div>';
			}
			if ( ! empty( $_GET['limits_saved'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' .
					esc_html__( 'Server limits saved.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
			}
			if ( ! empty( $_GET['audit_cleared'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' .
					esc_html__( 'Audit log cleared.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		// ------------------------------------------------------------------ //
		// Tab: Servers
		// ------------------------------------------------------------------ //

		/**
		 * Render the Servers list tab.
		 *
		 * @since 1.6.0
		 * @return void
		 */
		private function render_tab_servers() {
			if ( ! class_exists( 'WP_MCP_AI_Toolkit_Server_Registry' ) ) {
				echo '<p>' . esc_html__( 'Toolkit MCP Server framework is not loaded.', 'mcp-ai-wpoos-pro' ) . '</p>';
				return;
			}

			$servers = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->all();

			// Filters.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$filter_tier  = isset( $_GET['tier'] ) ? sanitize_text_field( wp_unslash( $_GET['tier'] ) ) : '';
			$filter_state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			$filter_s     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$tier_1_slugs = $this->get_tier1_slugs();
			?>
			<div class="wp-mcp-ai-servers-toolbar" style="margin:16px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
				<form method="get" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
					<input type="hidden" name="tab" value="servers">

					<input type="search" name="s" value="<?php echo esc_attr( $filter_s ); ?>"
						placeholder="<?php esc_attr_e( 'Search servers…', 'mcp-ai-wpoos-pro' ); ?>"
						style="max-width:200px;" class="regular-text">

					<select name="tier">
						<option value=""><?php esc_html_e( 'All Tiers', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="1" <?php selected( $filter_tier, '1' ); ?>><?php esc_html_e( 'Tier 1', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="2" <?php selected( $filter_tier, '2' ); ?>><?php esc_html_e( 'Tier 2', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>

					<select name="state">
						<option value=""><?php esc_html_e( 'All States', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="enabled" <?php selected( $filter_state, 'enabled' ); ?>><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="disabled" <?php selected( $filter_state, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>

					<?php submit_button( __( 'Filter', 'mcp-ai-wpoos-pro' ), 'secondary', 'filter_action', false ); ?>

					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=discovery' ) ); ?>"
						class="button button-secondary" target="_blank">
						<?php esc_html_e( '/.well-known/mcp', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</form>
			</div>

			<table class="wp-list-table widefat fixed striped wp-mcp-ai-servers-table">
				<thead>
					<tr>
						<th class="check-column"><input type="checkbox" id="cb-select-all-1"></th>
						<th style="width:30px;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Server', 'mcp-ai-wpoos-pro' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Tier', 'mcp-ai-wpoos-pro' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Tools', 'mcp-ai-wpoos-pro' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Tokens', 'mcp-ai-wpoos-pro' ); ?></th>
						<th style="width:160px;"><?php esc_html_e( 'Last Activity', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody id="wp-mcp-ai-servers-tbody">
				<?php
				$shown = 0;
				foreach ( $servers as $slug => $server ) {
					// Apply filters.
					$is_tier1    = in_array( $slug, $tier_1_slugs, true );
					$server_tier = $is_tier1 ? '1' : '2';
					$is_enabled  = $server instanceof WP_MCP_AI_Toolkit_Server_Base && $server->is_enabled();

					if ( '' !== $filter_tier && $filter_tier !== $server_tier ) {
						continue;
					}
					if ( 'enabled' === $filter_state && ! $is_enabled ) {
						continue;
					}
					if ( 'disabled' === $filter_state && $is_enabled ) {
						continue;
					}
					if ( '' !== $filter_s ) {
						$haystack = strtolower( $slug . ' ' . $server->get_name() );
						if ( false === strpos( $haystack, strtolower( $filter_s ) ) ) {
							continue;
						}
					}

					++$shown;

					// Token count.
					$token_option = get_option( 'wp_mcp_ai_tk_mcp_token_' . $slug, array() );
					$token_count  = is_array( $token_option ) ? count( $token_option ) : 0;

					// Last activity from audit log.
					$last_activity = $this->get_server_last_activity( $slug );

					// Tool count.
					$tool_count = $server instanceof WP_MCP_AI_Toolkit_Server_Base ? count( $server->effective_tool_slugs() ) : 0;

					// Detail URL.
					$detail_url = add_query_arg(
						array(
							'page'   => self::PAGE_SLUG,
							'tab'    => 'detail',
							'server' => rawurlencode( $slug ),
						),
						admin_url( 'admin.php' )
					);

					// Endpoint URL.
					$endpoint_url = rest_url( 'mcp-ai-pro/v1/mcp/' . $slug . '/jsonrpc' );
					?>
					<tr data-slug="<?php echo esc_attr( $slug ); ?>">
						<td class="check-column"><input type="checkbox" name="server[]" value="<?php echo esc_attr( $slug ); ?>"></td>
						<td>
							<span class="wp-mcp-ai-status-pill <?php echo $is_enabled ? 'enabled' : 'disabled'; ?>"
								title="<?php echo $is_enabled ? esc_attr__( 'Enabled', 'mcp-ai-wpoos-pro' ) : esc_attr__( 'Disabled', 'mcp-ai-wpoos-pro' ); ?>">
							</span>
						</td>
						<td>
							<strong><a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $server->get_name() ); ?></a></strong>
							<br><code style="font-size:11px;"><?php echo esc_html( $slug ); ?></code>
						</td>
						<td>
							<span class="wp-mcp-ai-tier-badge tier-<?php echo esc_attr( $server_tier ); ?>">
								<?php echo esc_html( 'Tier ' . $server_tier ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $tool_count ); ?></td>
						<td><?php echo esc_html( $token_count ); ?></td>
						<td>
							<?php
							echo '' !== $last_activity
								? esc_html( $last_activity )
								: '—';
							?>
						</td>
						<td class="wp-mcp-ai-row-actions">
							<a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">
								<?php esc_html_e( 'View', 'mcp-ai-wpoos-pro' ); ?>
							</a>
							<?php $this->render_toggle_form( $slug, $is_enabled ); ?>
							<button type="button"
								class="button button-small wp-mcp-ai-copy-endpoint"
								data-endpoint="<?php echo esc_attr( $endpoint_url ); ?>"
								title="<?php esc_attr_e( 'Copy endpoint URL', 'mcp-ai-wpoos-pro' ); ?>">
								<?php esc_html_e( 'Copy URL', 'mcp-ai-wpoos-pro' ); ?>
							</button>
						</td>
					</tr>
					<?php
				}
				if ( 0 === $shown ) {
					?>
					<tr>
						<td colspan="8" style="text-align:center;padding:20px;">
							<?php esc_html_e( 'No servers match the current filters.', 'mcp-ai-wpoos-pro' ); ?>
						</td>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>
			<p class="description" style="margin-top:8px;">
				<?php
				printf(
					/* translators: %d: total server count */
					esc_html__( '%d servers registered total.', 'mcp-ai-wpoos-pro' ),
					esc_html( count( $servers ) )
				);
				?>
			</p>
			<?php
		}

		/**
		 * Render an inline enable/disable toggle form button.
		 *
		 * @since 1.6.0
		 *
		 * @param string $slug      Server slug.
		 * @param bool   $is_enabled Current enabled state.
		 * @return void
		 */
		private function render_toggle_form( $slug, $is_enabled ) {
			$action_label = $is_enabled
				? __( 'Disable', 'mcp-ai-wpoos-pro' )
				: __( 'Enable', 'mcp-ai-wpoos-pro' );
			$btn_class    = $is_enabled
				? 'button button-small wp-mcp-ai-toggle-btn'
				: 'button button-small button-primary wp-mcp-ai-toggle-btn';
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				class="wp-mcp-ai-toggle-form" style="display:inline;">
				<input type="hidden" name="action" value="wp_mcp_ai_toggle_toolkit_mcp_server">
				<input type="hidden" name="server_slug" value="<?php echo esc_attr( $slug ); ?>">
				<input type="hidden" name="enable" value="<?php echo $is_enabled ? '0' : '1'; ?>">
				<?php wp_nonce_field( self::TOGGLE_NONCE ); ?>
				<button type="submit" class="<?php echo esc_attr( $btn_class ); ?>"
					data-slug="<?php echo esc_attr( $slug ); ?>"
					data-enable="<?php echo $is_enabled ? '0' : '1'; ?>"
					data-confirm="<?php echo $is_enabled ? esc_attr__( 'Disable this server? Clients connected to it will lose access.', 'mcp-ai-wpoos-pro' ) : ''; ?>">
					<?php echo esc_html( $action_label ); ?>
				</button>
			</form>
			<?php
		}

		// ------------------------------------------------------------------ //
		// Tab: Server Detail
		// ------------------------------------------------------------------ //

		/**
		 * Render the per-server detail tab.
		 *
		 * @since 1.6.0
		 *
		 * @param string $active_server Server slug from query string.
		 * @return void
		 */
		private function render_tab_detail( $active_server ) {
			if ( ! class_exists( 'WP_MCP_AI_Toolkit_Server_Registry' ) ) {
				echo '<p>' . esc_html__( 'Toolkit MCP Server framework is not loaded.', 'mcp-ai-wpoos-pro' ) . '</p>';
				return;
			}

			$servers = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->all();
			if ( empty( $servers ) ) {
				echo '<p>' . esc_html__( 'No servers registered.', 'mcp-ai-wpoos-pro' ) . '</p>';
				return;
			}

			// Server picker.
			if ( '' === $active_server || ! isset( $servers[ $active_server ] ) ) {
				$active_server = array_key_first( $servers );
			}
			$server = $servers[ $active_server ];
			?>
			<div class="wp-mcp-ai-detail-layout" style="display:flex;gap:20px;margin-top:12px;">
				<!-- Sidebar picker -->
				<div class="wp-mcp-ai-detail-sidebar" style="min-width:180px;max-width:200px;">
					<ul class="wp-mcp-ai-server-list-nav" style="margin:0;padding:0;list-style:none;">
					<?php foreach ( $servers as $slug => $s ) : ?>
						<?php
						$nav_url       = add_query_arg(
							array(
								'page'   => self::PAGE_SLUG,
								'tab'    => 'detail',
								'server' => rawurlencode( $slug ),
							),
							admin_url( 'admin.php' )
						);
						$is_active_nav = $slug === $active_server;
						?>
						<li>
							<a href="<?php echo esc_url( $nav_url ); ?>"
								class="wp-mcp-ai-detail-nav-link<?php echo $is_active_nav ? ' active' : ''; ?>">
								<?php echo esc_html( $s->get_name() ); ?>
							</a>
						</li>
					<?php endforeach; ?>
					</ul>
				</div>

				<!-- Detail panel -->
				<div class="wp-mcp-ai-detail-panel" style="flex:1;min-width:0;">
					<?php $this->render_server_detail_panel( $active_server, $server ); ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Render the five-accordion detail panel for a single server.
		 *
		 * @since 1.6.0
		 *
		 * @param string                             $slug   Server slug.
		 * @param WP_MCP_AI_Toolkit_Server_Interface $server Server instance.
		 * @return void
		 */
		private function render_server_detail_panel( $slug, $server ) {
			$config       = $this->get_server_config( $slug );
			$is_enabled   = ! empty( $config['enabled'] );
			$endpoint_url = rest_url( 'mcp-ai-pro/v1/mcp/' . $slug . '/jsonrpc' );
			?>
			<h2 style="display:flex;align-items:center;gap:10px;margin-top:0;">
				<?php echo esc_html( $server->get_name() ); ?>
				<span class="wp-mcp-ai-status-pill <?php echo $is_enabled ? 'enabled' : 'disabled'; ?>"></span>
				<?php $this->render_toggle_form( $slug, $is_enabled ); ?>
			</h2>
			<p class="description"><?php echo esc_html( $server->get_description() ); ?></p>

			<!-- ACCORDION 1: Overview -->
			<details class="wp-mcp-ai-accordion" open>
				<summary class="wp-mcp-ai-accordion-header"><?php esc_html_e( 'Overview', 'mcp-ai-wpoos-pro' ); ?></summary>
				<div class="wp-mcp-ai-accordion-body">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Slug', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><code><?php echo esc_html( $slug ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Version', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( $server->get_version() ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Tier', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<?php
								$tier = in_array( $slug, $this->get_tier1_slugs(), true ) ? 1 : 2;
								echo esc_html( 'Tier ' . $tier );
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'JSON-RPC Endpoint', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<code id="wp-mcp-ai-endpoint-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $endpoint_url ); ?></code>
								<button type="button" class="button button-small wp-mcp-ai-copy-endpoint"
									data-endpoint="<?php echo esc_attr( $endpoint_url ); ?>">
									<?php esc_html_e( 'Copy', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Candidate Tools', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<?php
								$candidate_count = count( $server->candidate_tool_slugs() );
								$effective_count = $server instanceof WP_MCP_AI_Toolkit_Server_Base ? count( $server->effective_tool_slugs() ) : $candidate_count;
								echo esc_html(
									sprintf(
									/* translators: 1: effective count, 2: total count */
										__( '%1$d active / %2$d candidate', 'mcp-ai-wpoos-pro' ),
										$effective_count,
										$candidate_count
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Ingestion Surfaces', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<?php
								$surfaces = $server->ingestion_surfaces();
								if ( empty( $surfaces ) ) {
									esc_html_e( 'None (tools-only server)', 'mcp-ai-wpoos-pro' );
								} else {
									echo esc_html( count( $surfaces ) . ' surface(s)' );
								}
								?>
							</td>
						</tr>
					</table>
				</div>
			</details>

			<!-- ACCORDION 2: Tools -->
			<details class="wp-mcp-ai-accordion">
				<summary class="wp-mcp-ai-accordion-header"><?php esc_html_e( 'Tools', 'mcp-ai-wpoos-pro' ); ?></summary>
				<div class="wp-mcp-ai-accordion-body">
					<p class="description">
						<?php esc_html_e( 'Tools currently exposed by this server. Manage the allowlist via the existing per-toolkit settings page.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
					<?php
					$effective_slugs = $server instanceof WP_MCP_AI_Toolkit_Server_Base ? $server->effective_tool_slugs() : $server->candidate_tool_slugs();
					if ( empty( $effective_slugs ) ) {
						echo '<p>' . esc_html__( 'No tools active.', 'mcp-ai-wpoos-pro' ) . '</p>';
					} else {
						echo '<ul class="wp-mcp-ai-tool-list">';
						foreach ( $effective_slugs as $tool_slug ) {
							echo '<li><code>' . esc_html( $tool_slug ) . '</code></li>';
						}
						echo '</ul>';
					}
					?>
				</div>
			</details>

			<!-- ACCORDION 3: Credentials -->
				<details class="wp-mcp-ai-accordion" id="wp-mcp-ai-creds-accordion">
					<summary class="wp-mcp-ai-accordion-header"><?php esc_html_e( 'Credentials', 'mcp-ai-wpoos-pro' ); ?></summary>
					<div class="wp-mcp-ai-accordion-body">
						<?php $this->render_credentials_panel( $slug ); ?>
					</div>
				</details>

				<!-- ACCORDION: OAuth Tokens -->
				<details class="wp-mcp-ai-accordion">
					<summary class="wp-mcp-ai-accordion-header">
						<?php esc_html_e( 'OAuth Tokens', 'mcp-ai-wpoos-pro' ); ?>
						<?php $this->render_oauth_token_count_badge( $slug ); ?>
					</summary>
					<div class="wp-mcp-ai-accordion-body">
						<?php $this->render_oauth_tokens_panel( $slug ); ?>
					</div>
				</details>

				<!-- ACCORDION 4: Limits -->
			<details class="wp-mcp-ai-accordion">
				<summary class="wp-mcp-ai-accordion-header"><?php esc_html_e( 'Limits', 'mcp-ai-wpoos-pro' ); ?></summary>
				<div class="wp-mcp-ai-accordion-body">
					<?php $this->render_limits_panel( $slug, $config ); ?>
				</div>
			</details>

			<!-- ACCORDION 5: Audit (filtered) -->
			<details class="wp-mcp-ai-accordion">
				<summary class="wp-mcp-ai-accordion-header"><?php esc_html_e( 'Audit (last 50)', 'mcp-ai-wpoos-pro' ); ?></summary>
				<div class="wp-mcp-ai-accordion-body">
					<?php $this->render_audit_entries( $slug, 50 ); ?>
					<p style="margin-top:8px;">
						<a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page'   => self::PAGE_SLUG,
									'tab'    => 'audit',
									'source' => rawurlencode( $slug ),
								),
								admin_url( 'admin.php' )
							)
						);
						?>
									">
							<?php esc_html_e( 'View all in Audit Log →', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				</div>
			</details>
			<?php
		}

		/**
		 * Render the credentials accordion body.
		 *
		 * @since 1.6.0
		 *
		 * @param string $slug Server slug.
		 * @return void
		 */
		private function render_credentials_panel( $slug ) {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Toolkit_Server_Token' ) ) {
				echo '<p>' . esc_html__( 'Token service unavailable.', 'mcp-ai-wpoos-pro' ) . '</p>';
				return;
			}

			$tokens = WP_MCP_AI_Pro_Toolkit_Server_Token::list_tokens( $slug );
			?>
			<p class="description">
				<?php esc_html_e( 'Bearer tokens for programmatic (non-user-session) access to this server\'s JSON-RPC endpoint.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<!-- Token table -->
			<?php if ( empty( $tokens ) ) : ?>
				<p><?php esc_html_e( 'No tokens issued.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped" style="margin-bottom:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Prefix', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Label', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Created', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Last Used', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tokens as $token ) : ?>
							<tr data-prefix="<?php echo esc_attr( $token['prefix'] ); ?>">
								<td><code><?php echo esc_html( $token['prefix'] . '…' ); ?></code></td>
								<td><?php echo esc_html( $token['label'] ?? '' ); ?></td>
								<td>
									<?php
									echo ! empty( $token['created_at'] )
										? esc_html( gmdate( get_option( 'date_format' ), $token['created_at'] ) )
										: '—';
									?>
								</td>
								<td>
									<?php
									echo ! empty( $token['last_used_at'] )
										? esc_html( human_time_diff( $token['last_used_at'] ) . ' ' . __( 'ago', 'mcp-ai-wpoos-pro' ) )
										: '—';
									?>
								</td>
								<td>
									<button type="button"
										class="button button-small button-link-delete wp-mcp-ai-revoke-token"
										data-slug="<?php echo esc_attr( $slug ); ?>"
										data-prefix="<?php echo esc_attr( $token['prefix'] ); ?>">
										<?php esc_html_e( 'Revoke', 'mcp-ai-wpoos-pro' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<!-- Generate form -->
			<?php
			$at_limit = count( $tokens ) >= WP_MCP_AI_Pro_Toolkit_Server_Token::MAX_TOKENS;
			if ( $at_limit ) {
				echo '<p class="description">' . sprintf(
					/* translators: %d: max tokens */
					esc_html__( 'Maximum of %d tokens reached. Revoke one before generating a new token.', 'mcp-ai-wpoos-pro' ),
					esc_html( WP_MCP_AI_Pro_Toolkit_Server_Token::MAX_TOKENS )
				) . '</p>';
			} else {
				?>
				<div class="wp-mcp-ai-generate-token" style="margin-top:8px;display:flex;gap:6px;align-items:flex-end;">
					<div>
						<label for="wp-mcp-ai-token-label-<?php echo esc_attr( $slug ); ?>" class="screen-reader-text">
							<?php esc_html_e( 'Token label', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<input type="text" id="wp-mcp-ai-token-label-<?php echo esc_attr( $slug ); ?>"
							class="regular-text wp-mcp-ai-token-label-input"
							placeholder="<?php esc_attr_e( 'Label (e.g. Claude Desktop)', 'mcp-ai-wpoos-pro' ); ?>"
							maxlength="100">
					</div>
					<button type="button"
						class="button button-primary wp-mcp-ai-generate-token-btn"
						data-slug="<?php echo esc_attr( $slug ); ?>">
						<?php esc_html_e( 'Generate Token', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</div>
				<?php
			}
			?>

			<!-- One-time reveal modal (hidden, populated by JS) -->
			<div id="wp-mcp-ai-token-modal-<?php echo esc_attr( $slug ); ?>"
				class="wp-mcp-ai-token-modal" style="display:none;"
				role="dialog" aria-modal="true"
				aria-label="<?php esc_attr_e( 'New token — save now', 'mcp-ai-wpoos-pro' ); ?>">
				<div class="wp-mcp-ai-token-modal-inner">
					<h2><?php esc_html_e( 'New Bearer Token', 'mcp-ai-wpoos-pro' ); ?></h2>
					<p class="description" style="color:#d63638;font-weight:600;">
						<?php esc_html_e( 'Copy and store this token now — it will not be shown again.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
					<div style="display:flex;gap:6px;align-items:center;margin:10px 0;">
						<input type="text" class="wp-mcp-ai-token-value regular-text" readonly
							style="font-family:monospace;font-size:13px;flex:1;" value="">
						<button type="button" class="button wp-mcp-ai-copy-token-btn">
							<?php esc_html_e( 'Copy', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</div>
					<p>
						<button type="button" class="button button-primary wp-mcp-ai-token-dismiss"
							data-slug="<?php echo esc_attr( $slug ); ?>">
							<?php esc_html_e( "I've saved this token — close", 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</p>
				</div>
			</div>
			<?php
		}

		/**
		 * Render OAuth token count badge for accordion summary.
		 *
		 * @since 1.7.0
		 *
		 * @param string $slug Server slug.
		 * @return void
		 */
		private function render_oauth_token_count_badge( $slug ) {
			$count = $this->count_oauth_tokens_for_audience( $slug );
			if ( $count > 0 ) {
				printf(
					' <span class="count" style="background:#2271b1;color:#fff;border-radius:10px;padding:0 6px;font-size:11px;">%d</span>',
					(int) $count
				);
			}
		}

		/**
		 * Render the OAuth tokens panel with active tokens and revoke button.
		 *
		 * @since 1.7.0
		 *
		 * @param string $slug Server slug.
		 * @return void
		 */
		private function render_oauth_tokens_panel( $slug ) {
			$slug = sanitize_key( $slug );

			// Build the expected audience for this server.
			$expected_audience = rest_url( 'mcp-ai-pro/v1/mcp/' . $slug );

			// Load OAuth tokens from the option store.
			$option_key = 'wp_mcp_ai_oauth_tokens';
			$all_tokens = get_option( $option_key, array() );
			if ( ! is_array( $all_tokens ) ) {
				$all_tokens = array();
			}

			$now             = time();
			$matching_tokens = array();

			foreach ( $all_tokens as $token_key => $entry ) {
				if ( ! empty( $entry['is_refresh'] ) ) {
					continue;
				}
				if ( ! isset( $entry['audience'] ) ) {
					continue;
				}
				if ( $entry['expires_at'] <= $now ) {
					continue;
				}

				// Match audience for this server.
				$token_aud = (string) $entry['audience'];
				if ( $token_aud !== $expected_audience ) {
					continue;
				}

				$prefix = '';
				if ( 0 === strpos( $token_key, 'mcp_at_' ) ) {
					$suffix = substr( $token_key, 7 );
					$prefix = substr( $suffix, 0, 16 );
				}

				$matching_tokens[] = array(
					'key'        => $token_key,
					'prefix'     => $prefix,
					'user_id'    => (int) ( $entry['user_id'] ?? 0 ),
					'user_login' => '',
					'scope'      => isset( $entry['scope'] ) ? (string) $entry['scope'] : '',
					'issued_at'  => (int) ( $entry['issued_at'] ?? 0 ),
					'expires_at' => (int) ( $entry['expires_at'] ?? 0 ),
				);
			}

			// Resolve usernames.
			foreach ( $matching_tokens as &$t ) {
				$user            = get_userdata( $t['user_id'] );
				$t['user_login'] = $user ? $user->user_login : '#' . $t['user_id'];
			}
			unset( $t );

			if ( empty( $matching_tokens ) ) {
				echo '<p>' . esc_html__( 'No active OAuth tokens for this server.', 'mcp-ai-wpoos-pro' ) . '</p>';
				echo '<p class="description">' . esc_html__( 'Tokens are issued when a user authenticates via OAuth 2.0 (e.g., using Codex or Claude Desktop with browser-based login).', 'mcp-ai-wpoos-pro' ) . '</p>';
				return;
			}

			$nonce = wp_create_nonce( 'wp_mcp_ai_revoke_oauth_token' );
			?>
			<p class="description">
				<?php esc_html_e( 'Active OAuth 2.0 access tokens issued for this server. Tokens are created when users authenticate via browser-based login.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<table class="widefat fixed striped" style="margin-bottom:12px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Token', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'User', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Scope', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Issued', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $matching_tokens as $token ) : ?>
						<tr>
							<td><code style="font-size:11px;">mcp_at_<?php echo esc_html( $token['prefix'] ); ?>…</code></td>
							<td><?php echo esc_html( $token['user_login'] ); ?></td>
							<td><code><?php echo esc_html( $token['scope'] ); ?></code></td>
							<td><?php echo esc_html( gmdate( 'Y-m-d H:i', $token['issued_at'] ) ); ?></td>
							<td>
								<?php
								$remaining = $token['expires_at'] - time();
								if ( $remaining < 300 ) {
									echo '<span style="color:#d63638;">';
									echo esc_html( gmdate( 'Y-m-d H:i', $token['expires_at'] ) );
									echo ' (' . esc_html( human_time_diff( $token['expires_at'] ) ) . ')</span>';
								} else {
									echo esc_html( gmdate( 'Y-m-d H:i', $token['expires_at'] ) );
								}
								?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<input type="hidden" name="action" value="wp_mcp_ai_revoke_oauth_token">
									<input type="hidden" name="token_key" value="<?php echo esc_attr( $token['key'] ); ?>">
									<input type="hidden" name="server_slug" value="<?php echo esc_attr( $slug ); ?>">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
									<button type="submit" class="button button-small"
										onclick="return confirm('<?php echo esc_js( __( 'Revoke this token? The user will need to re-authenticate.', 'mcp-ai-wpoos-pro' ) ); ?>')">
										<?php esc_html_e( 'Revoke', 'mcp-ai-wpoos-pro' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * Count OAuth tokens matching a server slug.
		 *
		 * @since 1.7.0
		 *
		 * @param string $slug Server slug.
		 * @return int
		 */
		private function count_oauth_tokens_for_audience( $slug ) {
			$expected = rest_url( 'mcp-ai-pro/v1/mcp/' . sanitize_key( $slug ) );
			$tokens   = get_option( 'wp_mcp_ai_oauth_tokens', array() );
			if ( ! is_array( $tokens ) ) {
				return 0;
			}
			$now   = time();
			$count = 0;
			foreach ( $tokens as $entry ) {
				if ( ! empty( $entry['is_refresh'] ) ) {
					continue;
				}
				if ( ! isset( $entry['audience'] ) ) {
					continue;
				}
				if ( $entry['expires_at'] <= $now ) {
					continue;
				}
				if ( (string) $entry['audience'] === $expected ) {
					++$count;
				}
			}
			return $count;
		}

		/**
		 * Render the per-server limits form.
		 *
		 * @since 1.6.0
		 *
		 * @param string $slug   Server slug.
		 * @param array  $config Current configuration.
		 * @return void
		 */
		private function render_limits_panel( $slug, $config ) {
			?>
			<p class="description">
				<?php esc_html_e( 'Per-server rate limits. 0 means "use global setting".', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wp_mcp_ai_save_toolkit_mcp_limits">
				<input type="hidden" name="server_slug" value="<?php echo esc_attr( $slug ); ?>">
				<?php wp_nonce_field( self::LIMITS_NONCE_PREFIX . $slug ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="rpm-<?php echo esc_attr( $slug ); ?>">
								<?php esc_html_e( 'Requests / Minute', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<input type="number" id="rpm-<?php echo esc_attr( $slug ); ?>"
								name="requests_per_minute"
								value="<?php echo esc_attr( $config['requests_per_minute'] ?? 0 ); ?>"
								min="0" max="10000" step="1" class="small-text">
							<p class="description"><?php esc_html_e( 'Max JSON-RPC requests per minute (0 = global).', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mpb-<?php echo esc_attr( $slug ); ?>">
								<?php esc_html_e( 'Max Payload (bytes)', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<input type="number" id="mpb-<?php echo esc_attr( $slug ); ?>"
								name="max_payload_bytes"
								value="<?php echo esc_attr( $config['max_payload_bytes'] ?? 0 ); ?>"
								min="0" max="104857600" step="1024" class="small-text">
							<p class="description"><?php esc_html_e( 'Max request body size in bytes (0 = global).', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mi-<?php echo esc_attr( $slug ); ?>">
								<?php esc_html_e( 'Max Iterations', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<input type="number" id="mi-<?php echo esc_attr( $slug ); ?>"
								name="max_iterations"
								value="<?php echo esc_attr( $config['max_iterations'] ?? 0 ); ?>"
								min="0" max="100" step="1" class="small-text">
							<p class="description"><?php esc_html_e( 'Max agentic loop iterations (0 = global).', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Limits', 'mcp-ai-wpoos-pro' ) ); ?>
			</form>
			<?php
		}

		// ------------------------------------------------------------------ //
		// Tab: Audit Log
		// ------------------------------------------------------------------ //

		/**
		 * Render the full Audit Log tab.
		 *
		 * @since 1.6.0
		 * @return void
		 */
		private function render_tab_audit() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$filter_source   = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
			$filter_consumer = isset( $_GET['consumer'] ) ? sanitize_text_field( wp_unslash( $_GET['consumer'] ) ) : '';
			$filter_action   = isset( $_GET['audit_action'] ) ? sanitize_text_field( wp_unslash( $_GET['audit_action'] ) ) : '';
			$filter_limit    = isset( $_GET['limit'] ) ? max( 10, min( 200, absint( wp_unslash( $_GET['limit'] ) ) ) ) : 50;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$entries = $this->get_audit_entries( $filter_source, $filter_consumer, $filter_action, $filter_limit );
			?>
			<div style="margin:16px 0 12px;">
				<form method="get" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
					<input type="hidden" name="tab" value="audit">

					<select name="source">
						<option value=""><?php esc_html_e( 'All Servers', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php
						if ( class_exists( 'WP_MCP_AI_Toolkit_Server_Registry' ) ) {
							foreach ( WP_MCP_AI_Toolkit_Server_Registry::get_instance()->slugs() as $sslug ) {
								printf(
									'<option value="%s"%s>%s</option>',
									esc_attr( $sslug ),
									selected( $filter_source, $sslug, false ),
									esc_html( $sslug )
								);
							}
						}
						?>
					</select>

					<input type="text" name="consumer" value="<?php echo esc_attr( $filter_consumer ); ?>"
						placeholder="<?php esc_attr_e( 'Consumer…', 'mcp-ai-wpoos-pro' ); ?>" style="max-width:180px;" class="regular-text">

					<select name="audit_action">
						<option value=""><?php esc_html_e( 'All Actions', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php
						$action_opts = array( 'resources/read', 'prompts/get', 'tools/call', 'admin' );
						foreach ( $action_opts as $ao ) {
							printf(
								'<option value="%s"%s>%s</option>',
								esc_attr( $ao ),
								selected( $filter_action, $ao, false ),
								esc_html( $ao )
							);
						}
						?>
					</select>

					<select name="limit">
						<?php
						foreach ( array( 25, 50, 100, 200 ) as $l ) {
							printf(
								'<option value="%d"%s>%s %d</option>',
								esc_attr( $l ),
								selected( $filter_limit, $l, false ),
								esc_html__( 'Last', 'mcp-ai-wpoos-pro' ),
								esc_html( $l )
							);
						}
						?>
					</select>

					<?php submit_button( __( 'Filter', 'mcp-ai-wpoos-pro' ), 'secondary', 'audit_filter', false ); ?>
					<button type="button" id="wp-mcp-ai-export-csv" class="button button-secondary"
						data-entries='<?php echo esc_attr( wp_json_encode( $entries ) ); ?>'>
						<?php esc_html_e( 'Export CSV', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<button type="button" id="wp-mcp-ai-export-json" class="button button-secondary"
						data-entries='<?php echo esc_attr( wp_json_encode( $entries ) ); ?>'>
						<?php esc_html_e( 'Export JSON', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</form>
			</div>

			<?php $this->render_audit_table( $entries ); ?>

			<!-- Clear audit log (destructive) -->
			<div style="margin-top:20px;padding-top:12px;border-top:1px solid #ddd;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					id="wp-mcp-ai-clear-audit-form">
					<input type="hidden" name="action" value="wp_mcp_ai_clear_toolkit_mcp_audit">
					<?php wp_nonce_field( self::CLEAR_AUDIT_NONCE ); ?>
					<button type="submit" class="button button-link-delete" id="wp-mcp-ai-clear-audit-btn">
						<?php esc_html_e( 'Clear Audit Log', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<span class="description" style="margin-left:6px;">
						<?php esc_html_e( 'Permanently removes all audit entries.', 'mcp-ai-wpoos-pro' ); ?>
					</span>
				</form>
			</div>
			<?php
		}

		/**
		 * Render audit entries inline (accordion widget inside Server Detail).
		 *
		 * @since 1.6.0
		 *
		 * @param string $slug  Source server slug to filter by.
		 * @param int    $limit Max entries.
		 * @return void
		 */
		private function render_audit_entries( $slug, $limit = 50 ) {
			$entries = $this->get_audit_entries( $slug, '', '', $limit );
			$this->render_audit_table( $entries );
		}

		/**
		 * Render an audit table for an array of entries.
		 *
		 * @since 1.6.0
		 *
		 * @param array $entries Audit entries.
		 * @return void
		 */
		private function render_audit_table( $entries ) {
			if ( empty( $entries ) ) {
				echo '<p>' . esc_html__( 'No audit entries found.', 'mcp-ai-wpoos-pro' ) . '</p>';
				return;
			}
			?>
			<table class="widefat fixed striped wp-mcp-ai-audit-table">
				<thead>
					<tr>
						<th style="width:160px;"><?php esc_html_e( 'Timestamp', 'mcp-ai-wpoos-pro' ); ?></th>
						<th style="width:100px;"><?php esc_html_e( 'Server', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Consumer', 'mcp-ai-wpoos-pro' ); ?></th>
						<th style="width:120px;"><?php esc_html_e( 'Action', 'mcp-ai-wpoos-pro' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Result', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td>
								<?php
								if ( ! empty( $entry['ts'] ) ) {
									echo esc_html( gmdate( 'Y-m-d H:i:s', $entry['ts'] ) );
								}
								?>
							</td>
							<td><code><?php echo esc_html( $entry['source'] ?? '' ); ?></code></td>
							<td><?php echo esc_html( $entry['consumer'] ?? '' ); ?></td>
							<td><code><?php echo esc_html( $entry['action'] ?? '' ); ?></code></td>
							<td>
								<?php
								$result = $entry['result'] ?? '';
								$color  = 'ok' === $result ? '#46b450' : ( 'error' === $result ? '#d63638' : '' );
								echo '<span style="' . ( $color ? 'color:' . esc_attr( $color ) . ';font-weight:600;' : '' ) . '">' .
									esc_html( $result ) . '</span>';
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="margin-top:4px;">
				<?php
				printf(
					/* translators: %d: entry count */
					esc_html__( '%d entries shown.', 'mcp-ai-wpoos-pro' ),
					esc_html( count( $entries ) )
				);
				?>
			</p>
			<?php
		}

		// ------------------------------------------------------------------ //
		// Tab: Discovery
		// ------------------------------------------------------------------ //

		/**
		 * Render the Discovery tab.
		 *
		 * @since 1.6.0
		 * @return void
		 */
		private function render_tab_discovery() {
			$well_known_url = home_url( '/.well-known/mcp' );
			?>
			<div style="margin-top:16px;">
				<p>
					<strong><?php esc_html_e( '/.well-known/mcp document', 'mcp-ai-wpoos-pro' ); ?></strong>
					<a href="<?php echo esc_url( $well_known_url ); ?>" target="_blank"
						class="button button-small" style="margin-left:8px;">
						<?php esc_html_e( 'Open raw', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<button type="button" id="wp-mcp-ai-copy-discovery" class="button button-small"
						style="margin-left:4px;">
						<?php esc_html_e( 'Copy JSON', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<button type="button" id="wp-mcp-ai-refresh-discovery" class="button button-small"
						style="margin-left:4px;">
						<?php esc_html_e( 'Refresh', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</p>

				<div id="wp-mcp-ai-discovery-preview"
					style="background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;
					padding:16px;border-radius:4px;max-height:500px;overflow:auto;white-space:pre;">
					<?php esc_html_e( 'Loading…', 'mcp-ai-wpoos-pro' ); ?>
				</div>

				<p class="description" style="margin-top:8px;">
					<?php
					printf(
						/* translators: %s: filter hook name */
						esc_html__( 'Customise this document via the %s filter.', 'mcp-ai-wpoos-pro' ),
						'<code>wp_mcp_ai_well_known_mcp_document</code>'
					);
					?>
					<?php
					printf(
						/* translators: %s: filter hook name */
						esc_html__( 'Cache TTL is controlled by the %s filter.', 'mcp-ai-wpoos-pro' ),
						'<code>wp_mcp_ai_well_known_mcp_cache_max_age</code>'
					);
					?>
				</p>
			</div>
			<?php
		}

		// ------------------------------------------------------------------ //
		// Tab: Help
		// ------------------------------------------------------------------ //

		/**
		 * Render the Help tab.
		 *
		 * @since 1.6.0
		 * @return void
		 */
		private function render_tab_help() {
			?>
			<div style="max-width:800px;margin-top:16px;">
				<h2><?php esc_html_e( 'Quick Start', 'mcp-ai-wpoos-pro' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Use the Servers tab to enable or disable individual toolkit MCP servers.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Open Server Detail → Credentials to generate a bearer token for programmatic access.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Point your MCP client at the JSON-RPC Endpoint URL shown in Server Detail → Overview.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Use the Audit Log tab to monitor cross-mount reads and tool calls.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'The Discovery tab shows the /.well-known/mcp document that MCP clients use for auto-discovery.', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<h2><?php esc_html_e( 'Slash-Command Equivalents', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'UI Action', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Slash Command', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr><td><?php esc_html_e( 'List servers', 'mcp-ai-wpoos-pro' ); ?></td><td><code>/mcp-server list</code></td></tr>
						<tr><td><?php esc_html_e( 'Show server details', 'mcp-ai-wpoos-pro' ); ?></td><td><code>/mcp-server show {slug}</code></td></tr>
						<tr><td><?php esc_html_e( 'Enable a server', 'mcp-ai-wpoos-pro' ); ?></td><td><code>/mcp-server enable {slug}</code></td></tr>
						<tr><td><?php esc_html_e( 'Disable a server', 'mcp-ai-wpoos-pro' ); ?></td><td><code>/mcp-server disable {slug}</code></td></tr>
						<tr><td><?php esc_html_e( 'List server tools', 'mcp-ai-wpoos-pro' ); ?></td><td><code>/mcp-server tools {slug}</code></td></tr>
					</tbody>
				</table>

				<h2 style="margin-top:20px;"><?php esc_html_e( 'WP-CLI Equivalents', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'UI Action', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'WP-CLI Command', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr><td><?php esc_html_e( 'List servers', 'mcp-ai-wpoos-pro' ); ?></td><td><code>wp mcp-server list</code></td></tr>
						<tr><td><?php esc_html_e( 'Generate token', 'mcp-ai-wpoos-pro' ); ?></td><td><code>wp mcp-server token-generate {slug} --label="…"</code></td></tr>
						<tr><td><?php esc_html_e( 'List tokens', 'mcp-ai-wpoos-pro' ); ?></td><td><code>wp mcp-server token-list {slug}</code></td></tr>
						<tr><td><?php esc_html_e( 'Revoke token', 'mcp-ai-wpoos-pro' ); ?></td><td><code>wp mcp-server token-revoke {slug} {prefix}</code></td></tr>
					</tbody>
				</table>

				<h2 style="margin-top:20px;"><?php esc_html_e( 'Hooks Reference', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Hook', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr><td><code>wp_mcp_ai_toolkit_mcp_server_toggled</code></td><td><?php esc_html_e( 'Fires when a server is enabled/disabled via the admin UI.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>wp_mcp_ai_toolkit_mcp_audit_max_entries</code></td><td><?php esc_html_e( 'Filter: max entries retained in the audit ring buffer (default 200).', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>wp_mcp_ai_toolkit_mcp_cross_mount_read</code></td><td><?php esc_html_e( 'Action: fires on every cross-mount resources/read and prompts/get.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>wp_mcp_ai_well_known_mcp_document</code></td><td><?php esc_html_e( 'Filter: customise the /.well-known/mcp discovery document.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>wp_mcp_ai_well_known_mcp_cache_max_age</code></td><td><?php esc_html_e( 'Filter: Cache-Control max-age for the discovery endpoint.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					</tbody>
				</table>

				<p style="margin-top:16px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-mcp-servers-docs' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'docs/mcp-servers.md', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/toolkit-mcp-servers.md"
						target="_blank" class="button button-secondary" style="margin-left:6px;">
						<?php esc_html_e( 'Feature docs', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		// ------------------------------------------------------------------ //
		// Helpers
		// ------------------------------------------------------------------ //

		/**
		 * Get per-server config array from the option.
		 *
		 * @since 1.6.0
		 *
		 * @param string $slug Server slug.
		 * @return array<string,mixed>
		 */
		private function get_server_config( $slug ) {
			$defaults = array(
				'enabled'             => true,
				'tools_allowlist'     => array(),
				'disabled_surfaces'   => array(),
				'disabled_mounts'     => array(),
				'requests_per_minute' => 0,
				'max_payload_bytes'   => 0,
				'max_iterations'      => 0,
			);
			$option   = get_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . $slug, array() );
			if ( ! is_array( $option ) ) {
				$option = array();
			}
			return array_merge( $defaults, $option );
		}

		/**
		 * Get audit entries with optional filters.
		 *
		 * @since 1.6.0
		 *
		 * @param string $source   Filter by source server slug.
		 * @param string $consumer Filter by consumer string.
		 * @param string $action   Filter by action type.
		 * @param int    $limit    Max entries to return.
		 * @return array
		 */
		private function get_audit_entries( $source = '', $consumer = '', $action = '', $limit = 50 ) {
			if ( ! class_exists( 'WP_MCP_AI_Toolkit_MCP_Audit_Log' ) ) {
				return array();
			}
			$all = WP_MCP_AI_Toolkit_MCP_Audit_Log::get_instance()->get_entries( 200 );
			if ( '' !== $source ) {
				$all = array_filter(
					$all,
					static function ( $e ) use ( $source ) {
						return isset( $e['source'] ) && $e['source'] === $source;
					}
				);
			}
			if ( '' !== $consumer ) {
				$all = array_filter(
					$all,
					static function ( $e ) use ( $consumer ) {
						return isset( $e['consumer'] ) && false !== strpos( $e['consumer'], $consumer );
					}
				);
			}
			if ( '' !== $action ) {
				$all = array_filter(
					$all,
					static function ( $e ) use ( $action ) {
						return isset( $e['action'] ) && $e['action'] === $action;
					}
				);
			}
			$all = array_values( $all );
			usort(
				$all,
				static function ( $a, $b ) {
					return ( $b['ts'] ?? 0 ) - ( $a['ts'] ?? 0 );
				}
			);
			return array_slice( $all, 0, $limit );
		}

		/**
		 * Get the human-readable last activity time for a server slug.
		 *
		 * @since 1.6.0
		 *
		 * @param string $slug Server slug.
		 * @return string Human time diff or empty string.
		 */
		private function get_server_last_activity( $slug ) {
			if ( ! class_exists( 'WP_MCP_AI_Toolkit_MCP_Audit_Log' ) ) {
				return '';
			}
			$entries = WP_MCP_AI_Toolkit_MCP_Audit_Log::get_instance()->get_entries( 200 );
			$latest  = 0;
			foreach ( $entries as $entry ) {
				if ( isset( $entry['source'] ) && $entry['source'] === $slug && isset( $entry['ts'] ) ) {
					if ( (int) $entry['ts'] > $latest ) {
						$latest = (int) $entry['ts'];
					}
				}
			}
			return $latest > 0 ? human_time_diff( $latest ) . ' ' . __( 'ago', 'mcp-ai-wpoos-pro' ) : '';
		}

		/**
		 * Returns the list of Tier-1 server slugs.
		 *
		 * @since 1.6.0
		 * @return string[]
		 */
		private function get_tier1_slugs() {
			return array(
				'crm',
				'health',
				'architectural-design',
				'ai-tool-builder',
				'calendar-booking',
				'cre-debt',
				'dj-management',
				'document-generation',
				'eca',
				'ecommerce',
				'financial-planner',
				'image-production',
				'law-firm',
				'media',
				'multilingual',
				'project-management',
				'regulatory-registration',
				'social-media',
				'video-production',
			);
		}
	}
}
