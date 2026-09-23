<?php
/**
 * MCP Apps Metabox for Assistants.
 *
 * Provides the admin UI for configuring MCP App connections
 * per assistant, following the MCP Apps extension (SEP-1865).
 *
 * @package WP_MCP_AI
 * @since   1.8.0
 * @see     https://modelcontextprotocol.io/extensions/apps/overview
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the MCP Apps metabox on the assistant editor.
 *
 * Allows administrators to connect remote MCP servers to an assistant,
 * enabling tool discovery and UI resource integration per the MCP
 * specification and SEP-1865 Apps extension.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_Metabox_MCP_Apps extends WP_MCP_AI_Metabox_Base {

	/**
	 * Reference to the Assistant CPT class.
	 *
	 * @var WP_MCP_AI_Assistant_CPT
	 */
	protected $cpt;

	/**
	 * Constructor.
	 *
	 * @since 1.8.0
	 * @param WP_MCP_AI_Assistant_CPT $cpt Assistant CPT instance.
	 */
	public function __construct( $cpt ) {
		$this->cpt = $cpt;
	}

	/**
	 * Get the metabox ID.
	 *
	 * @since 1.8.0
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_mcp_apps';
	}

	/**
	 * Get the metabox title.
	 *
	 * @since 1.8.0
	 * @return string
	 */
	public function get_title() {
		return __( 'MCP Apps', 'mcp-ai-wpoos' );
	}

	/**
	 * Get documentation URL for this metabox.
	 *
	 * @since 1.8.0
	 * @return string
	 */
	public function get_documentation_url() {
		return 'https://modelcontextprotocol.io/extensions/apps/overview';
	}

	/**
	 * Check if current user has permission to view this metabox.
	 *
	 * @since 1.8.0
	 * @return bool
	 */
	protected function can_view() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Render the metabox content.
	 *
	 * @since 1.8.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied( __( 'You do not have permission to manage MCP Apps.', 'mcp-ai-wpoos' ) );
			return;
		}

		wp_nonce_field( 'wp_mcp_ai_mcp_apps_meta', 'wp_mcp_ai_mcp_apps_meta_nonce' );

		$apps     = array();
		$statuses = array();
		if ( class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
			$apps     = $registry->get_apps( $post->ID );
			$statuses = $registry->get_app_status( $post->ID );
		}

		?>
		<div class="wp-mcp-ai-mcp-apps">
			<p class="description">
				<?php
				printf(
					/* translators: %s: URL to the MCP Apps specification */
					esc_html__( 'Connect remote MCP servers as apps to extend this assistant with external tools and interactive UIs. Apps follow the %s specification.', 'mcp-ai-wpoos' ),
					'<a href="https://modelcontextprotocol.io/extensions/apps/overview" target="_blank" rel="noopener noreferrer">' . esc_html__( 'MCP Apps (SEP-1865)', 'mcp-ai-wpoos' ) . '</a>'
				);
				?>
			</p>

			<div id="wp-mcp-ai-mcp-apps-list">
				<?php
				if ( empty( $apps ) ) {
					$this->render_empty_state();
				} else {
					foreach ( $apps as $index => $app ) {
						$status = isset( $statuses[ $registry->get_app_status_key( $app ) ] ) ? $statuses[ $registry->get_app_status_key( $app ) ] : array();
						$this->render_app_row( $index, $app, $status );
					}
				}
				?>
			</div>

			<p style="margin-top: 15px;">
				<button type="button" class="button button-secondary" id="wp-mcp-ai-add-mcp-app">
					<span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span>
					<?php esc_html_e( 'Add MCP App', 'mcp-ai-wpoos' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="wp-mcp-ai-test-all-mcp-apps">
					<span class="dashicons dashicons-update-alt" style="vertical-align: text-bottom;"></span>
					<?php esc_html_e( 'Test All', 'mcp-ai-wpoos' ); ?>
				</button>
				<button type="button" class="button button-link" id="wp-mcp-ai-import-mcp-apps">
					<span class="dashicons dashicons-upload" style="vertical-align: text-bottom;"></span>
					<?php esc_html_e( 'Import from JSON', 'mcp-ai-wpoos' ); ?>
				</button>
			</p>

			<div id="wp-mcp-ai-import-mcp-apps-panel" style="display:none; margin: 10px 0;">
				<label for="wp-mcp-ai-import-mcp-apps-json"><?php esc_html_e( 'Paste a generated mcpServers JSON block (e.g. from Claude Desktop, Cursor, or an NV oOS site):', 'mcp-ai-wpoos' ); ?></label>
				<textarea id="wp-mcp-ai-import-mcp-apps-json" rows="6" class="large-text code" placeholder='{"mcpServers":{"my-server":{"type":"http","url":"https://example.com/mcp","headers":{"Authorization":"Basic …"}}}}'></textarea>
				<p>
					<button type="button" class="button button-primary" id="wp-mcp-ai-import-mcp-apps-apply"><?php esc_html_e( 'Apply Import', 'mcp-ai-wpoos' ); ?></button>
					<button type="button" class="button button-link" id="wp-mcp-ai-import-mcp-apps-cancel"><?php esc_html_e( 'Cancel', 'mcp-ai-wpoos' ); ?></button>
				</p>
			</div>

			<p class="description" style="margin-top: 10px;">
				<?php
				printf(
					/* translators: %d: Maximum number of apps allowed. */
					esc_html__( 'Maximum %d MCP Apps per assistant. Each app connects to a remote MCP server via Streamable HTTP transport.', 'mcp-ai-wpoos' ),
					10
				);
				?>
			</p>
		</div>

		<?php
		$this->render_app_template();
		$this->render_script();
		$this->render_documentation_link();

		wp_register_style( 'wp-mcp-ai-metabox-mcp-apps', false, array(), WP_MCP_AI_VERSION );
		wp_enqueue_style( 'wp-mcp-ai-metabox-mcp-apps' );
		wp_add_inline_style(
			'wp-mcp-ai-metabox-mcp-apps',
			'.wp-mcp-ai-mcp-app-status-badge{display:inline-flex;align-items:center;gap:6px;margin-left:10px;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:600;vertical-align:middle}'
			. '.wp-mcp-ai-mcp-app-status-dot{width:8px;height:8px;border-radius:50%;display:inline-block}'
			. '.wp-mcp-ai-mcp-app-status-ok{background:#e8f5e9;color:#1e7a1e}'
			. '.wp-mcp-ai-mcp-app-status-ok .wp-mcp-ai-mcp-app-status-dot{background:#00a32a}'
			. '.wp-mcp-ai-mcp-app-status-error{background:#ffebee;color:#b3261e}'
			. '.wp-mcp-ai-mcp-app-status-error .wp-mcp-ai-mcp-app-status-dot{background:#d63638}'
			. '.wp-mcp-ai-mcp-app-status-unknown{background:#f0f0f1;color:#646970}'
			. '.wp-mcp-ai-mcp-app-status-unknown .wp-mcp-ai-mcp-app-status-dot{background:#8c8f94}'
			. '.wp-mcp-ai-mcp-app-tool-count{margin-left:8px;padding:2px 8px;border-radius:8px;background:#eef3fa;color:#1d4ed8;font-size:11px;font-weight:600}'
		);
	}

	/**
	 * Save metabox data.
	 *
	 * @since 1.8.0
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['wp_mcp_ai_mcp_apps_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_mcp_apps_meta_nonce'] ) ), 'wp_mcp_ai_mcp_apps_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$apps = array();

		if ( isset( $_POST['wp_mcp_ai_mcp_apps'] ) && is_array( $_POST['wp_mcp_ai_mcp_apps'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_app_config.
			$raw_apps = wp_unslash( $_POST['wp_mcp_ai_mcp_apps'] );

			// Preserve existing tokens when the masked token field was left
			// empty (see render_app_row — stored tokens are not echoed back).
			$existing_apps = array();
			if ( class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
				$existing_apps = WP_MCP_AI_MCP_App_Registry::get_instance()->get_apps( $post_id );
			}

			foreach ( $raw_apps as $index => $raw_app ) {
				if ( ! is_array( $raw_app ) ) {
					continue;
				}

				if ( empty( $raw_app['token'] ) && ! empty( $existing_apps[ $index ]['token'] ) ) {
					$raw_app['token'] = $existing_apps[ $index ]['token'];
				}

				$sanitized = WP_MCP_AI_MCP_App_Registry::sanitize_app_config( $raw_app );
				if ( ! empty( $sanitized['server_url'] ) ) {
					$apps[] = $sanitized;
				}
			}
		}

		if ( class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
			$registry->save_apps( $post_id, $apps );
		} elseif ( empty( $apps ) ) {
			delete_post_meta( $post_id, WP_MCP_AI_MCP_App_Registry::META_KEY );
		} else {
			$sanitized_apps = array();
			foreach ( $apps as $app ) {
				$sanitized_apps[] = WP_MCP_AI_MCP_App_Registry::sanitize_app_config( $app );
			}
			update_post_meta( $post_id, WP_MCP_AI_MCP_App_Registry::META_KEY, array_slice( $sanitized_apps, 0, 10 ) );
		}
	}

	/**
	 * Render the empty state when no apps are configured.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	protected function render_empty_state() {
		?>
		<div class="wp-mcp-ai-mcp-apps-empty" id="wp-mcp-ai-mcp-apps-empty" style="padding: 20px; text-align: center; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px; margin: 15px 0;">
			<span class="dashicons dashicons-cloud" style="font-size: 32px; color: #999; display: block; margin-bottom: 10px;"></span>
			<p><?php esc_html_e( 'No MCP Apps connected yet.', 'mcp-ai-wpoos' ); ?></p>
			<p class="description"><?php esc_html_e( 'Add a remote MCP server to extend this assistant with external tools and interactive UI resources.', 'mcp-ai-wpoos' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render a single MCP App configuration row.
	 *
	 * @since 1.8.0
	 * @since 1.9.1 Added $status parameter for the connection badge.
	 * @param int   $index  App index.
	 * @param array $app    App configuration.
	 * @param array $status Optional connection status snapshot.
	 * @return void
	 */
	protected function render_app_row( $index, $app, $status = array() ) {
		$app = wp_parse_args(
			$app,
			array(
				'label'       => '',
				'server_url'  => '',
				'auth_type'   => 'none',
				'token'       => '',
				'header_name' => '',
				'enabled'     => true,
				'timeout'     => 30,
				'verify_ssl'  => true,
				'oauth_data'  => array(),
			)
		);

		$prefix       = 'wp_mcp_ai_mcp_apps[' . $index . ']';
		$has_status   = ! empty( $status['last_status'] );
		$status_class = $has_status && 'ok' === $status['last_status'] ? 'ok' : ( $has_status ? 'error' : 'unknown' );
		$status_text  = $has_status && 'ok' === $status['last_status'] ? __( 'Connected', 'mcp-ai-wpoos' ) : ( $has_status ? __( 'Error', 'mcp-ai-wpoos' ) : __( 'Not tested', 'mcp-ai-wpoos' ) );
		$tool_count   = $has_status && isset( $status['tool_count'] ) && null !== $status['tool_count'] ? (int) $status['tool_count'] : null;
		$last_error   = $has_status && ! empty( $status['last_error'] ) ? $status['last_error'] : '';
		?>
		<div class="wp-mcp-ai-mcp-app-row" style="border: 1px solid #dcdcde; border-radius: 3px; padding: 15px; margin: 10px 0; background: #fff;">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
				<span>
					<strong class="wp-mcp-ai-mcp-app-title">
						<?php echo esc_html( ! empty( $app['label'] ) ? $app['label'] : __( 'MCP App', 'mcp-ai-wpoos' ) ); ?>
					</strong>
					<span class="wp-mcp-ai-mcp-app-status-badge wp-mcp-ai-mcp-app-status-<?php echo esc_attr( $status_class ); ?>" <?php echo $last_error ? 'title="' . esc_attr( $last_error ) . '"' : ''; ?>>
						<span class="wp-mcp-ai-mcp-app-status-dot"></span>
						<span class="wp-mcp-ai-mcp-app-status-text"><?php echo esc_html( $status_text ); ?></span>
					</span>
					<?php if ( null !== $tool_count ) : ?>
						<span class="wp-mcp-ai-mcp-app-tool-count">
							<?php
							printf(
								/* translators: %d: number of tools. */
								esc_html( _n( '%d tool', '%d tools', $tool_count, 'mcp-ai-wpoos' ) ),
								(int) $tool_count
							);
							?>
						</span>
					<?php endif; ?>
				</span>
				<div>
					<label style="margin-right: 10px;">
						<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[enabled]" value="0" />
						<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[enabled]" value="1" <?php checked( $app['enabled'] ); ?> />
						<?php esc_html_e( 'Enabled', 'mcp-ai-wpoos' ); ?>
					</label>
					<button type="button" class="button button-link-delete wp-mcp-ai-remove-mcp-app"><?php esc_html_e( 'Remove', 'mcp-ai-wpoos' ); ?></button>
				</div>
			</div>

			<table class="form-table" style="margin: 0;">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $app['label'] ); ?>" class="regular-text wp-mcp-ai-mcp-app-label" placeholder="<?php esc_attr_e( 'My MCP App', 'mcp-ai-wpoos' ); ?>" />
						<p class="description"><?php esc_html_e( 'A friendly name for this MCP App connection.', 'mcp-ai-wpoos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Server URL', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<input type="url" name="<?php echo esc_attr( $prefix ); ?>[server_url]" value="<?php echo esc_attr( $app['server_url'] ); ?>" class="regular-text" placeholder="https://example.com/mcp" required />
						<p class="description"><?php esc_html_e( 'The remote MCP server endpoint URL (Streamable HTTP transport).', 'mcp-ai-wpoos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Authentication', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( $prefix ); ?>[auth_type]" class="wp-mcp-ai-mcp-app-auth-type">
							<option value="none" <?php selected( $app['auth_type'], 'none' ); ?>><?php esc_html_e( 'None', 'mcp-ai-wpoos' ); ?></option>
							<option value="bearer" <?php selected( $app['auth_type'], 'bearer' ); ?>><?php esc_html_e( 'Bearer Token', 'mcp-ai-wpoos' ); ?></option>
							<option value="basic" <?php selected( $app['auth_type'], 'basic' ); ?>><?php esc_html_e( 'Basic Auth (User:Password)', 'mcp-ai-wpoos' ); ?></option>
							<option value="header" <?php selected( $app['auth_type'], 'header' ); ?>><?php esc_html_e( 'Custom Header', 'mcp-ai-wpoos' ); ?></option>
							<option value="oauth" <?php selected( $app['auth_type'], 'oauth' ); ?>><?php esc_html_e( 'OAuth 2.0 Web Login', 'mcp-ai-wpoos' ); ?></option>
						</select>
					</td>
				</tr>
				<tr class="wp-mcp-ai-mcp-app-token-row" <?php echo in_array( $app['auth_type'], array( 'none', 'oauth' ), true ) ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><label><?php esc_html_e( 'Token / API Key', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="password" name="<?php echo esc_attr( $prefix ); ?>[token]" value="" class="regular-text wp-mcp-ai-mcp-app-token-input" autocomplete="off" placeholder="<?php echo ! empty( $app['token'] ) ? esc_attr__( '•••••••• (unchanged)', 'mcp-ai-wpoos' ) : ''; ?>" />
							<p class="description">
								<?php
								if ( ! empty( $app['token'] ) ) {
									esc_html_e( 'A credential is stored. Leave blank to keep it, or type a new one to replace it.', 'mcp-ai-wpoos' );
								} else {
									esc_html_e( 'For Basic Auth, enter user:password or a pre-encoded base64 credential.', 'mcp-ai-wpoos' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr class="wp-mcp-ai-mcp-app-header-row" <?php echo 'header' !== $app['auth_type'] ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><label><?php esc_html_e( 'Header Name', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="text" name="<?php echo esc_attr( $prefix ); ?>[header_name]" value="<?php echo esc_attr( $app['header_name'] ); ?>" class="regular-text" placeholder="X-API-Key" />
						</td>
					</tr>
					<tr class="wp-mcp-ai-mcp-app-oauth-row" <?php echo 'oauth' !== $app['auth_type'] ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><label><?php esc_html_e( 'OAuth Login', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<?php
							$has_oauth = ! empty( $app['oauth_data']['access_token'] );
							if ( $has_oauth ) :
								$scope      = ! empty( $app['oauth_data']['scope'] ) ? $app['oauth_data']['scope'] : __( 'default', 'mcp-ai-wpoos' );
								$issued_at  = ! empty( $app['oauth_data']['issued_at'] ) ? (int) $app['oauth_data']['issued_at'] : 0;
								$expires_in = ! empty( $app['oauth_data']['expires_in'] ) ? (int) $app['oauth_data']['expires_in'] : 3600;
								$expires_at = $issued_at + $expires_in;
								?>
								<p>
									<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
									<?php esc_html_e( 'Authenticated via OAuth 2.0.', 'mcp-ai-wpoos' ); ?>
									<?php if ( $scope ) : ?>
										(<?php esc_html_e( 'Scope', 'mcp-ai-wpoos' ); ?>: <?php echo esc_html( $scope ); ?>)
									<?php endif; ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'Tokens are stored securely and automatically refreshed.', 'mcp-ai-wpoos' ); ?>
								</p>
								<?php
							else :
								?>
								<p class="description">
									<?php esc_html_e( 'Click the button below to connect via browser-based OAuth 2.0 login.', 'mcp-ai-wpoos' ); ?>
								</p>
								<?php
							endif;
							?>
							<button type="button" class="button wp-mcp-ai-connect-oauth"
								data-server-url="<?php echo esc_url( $app['server_url'] ); ?>"
								<?php echo $has_oauth ? 'style="display:none;"' : ''; ?>>
								<?php esc_html_e( 'Connect via Web Login', 'mcp-ai-wpoos' ); ?>
							</button>
							<button type="button" class="button wp-mcp-ai-reconnect-oauth"
								data-server-url="<?php echo esc_url( $app['server_url'] ); ?>"
								<?php echo ! $has_oauth ? 'style="display:none;"' : ''; ?>>
								<?php esc_html_e( 'Re-authenticate', 'mcp-ai-wpoos' ); ?>
							</button>
							<?php
							foreach ( array( 'access_token', 'refresh_token', 'token_type', 'expires_in', 'scope', 'issued_at' ) as $field ) {
								$value = isset( $app['oauth_data'][ $field ] ) ? $app['oauth_data'][ $field ] : '';
								?>
								<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[oauth_data][<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" />
								<?php
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Timeout', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $prefix ); ?>[timeout]" value="<?php echo esc_attr( $app['timeout'] ); ?>" min="1" max="120" style="width: 80px;" />
						<span class="description"><?php esc_html_e( 'seconds', 'mcp-ai-wpoos' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Verify SSL', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[verify_ssl]" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[verify_ssl]" value="1" <?php checked( $app['verify_ssl'] ); ?> />
							<?php esc_html_e( 'Verify SSL certificate on the remote server.', 'mcp-ai-wpoos' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<div class="wp-mcp-ai-mcp-app-actions" style="margin-top: 12px;">
				<button type="button" class="button wp-mcp-ai-test-mcp-app">
					<span class="dashicons dashicons-admin-links" style="vertical-align: text-bottom;"></span>
					<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
				</button>
				<button type="button" class="button wp-mcp-ai-discover-mcp-app">
					<span class="dashicons dashicons-search" style="vertical-align: text-bottom;"></span>
					<?php esc_html_e( 'Discover Tools', 'mcp-ai-wpoos' ); ?>
				</button>
				<span class="spinner wp-mcp-ai-mcp-app-spinner" style="display:none; float:none; margin-top:0;"></span>
			</div>

			<div class="wp-mcp-ai-mcp-app-result" style="display:none; margin-top: 10px;"></div>
		</div>
		<?php
	}

	/**
	 * Render the JavaScript template for adding new app rows.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	protected function render_app_template() {
		?>
		<script type="text/html" id="tmpl-wp-mcp-ai-mcp-app-row">
			<div class="wp-mcp-ai-mcp-app-row" style="border: 1px solid #dcdcde; border-radius: 3px; padding: 15px; margin: 10px 0; background: #fff;">
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
					<span>
						<strong class="wp-mcp-ai-mcp-app-title"><?php esc_html_e( 'New MCP App', 'mcp-ai-wpoos' ); ?></strong>
						<span class="wp-mcp-ai-mcp-app-status-badge wp-mcp-ai-mcp-app-status-unknown">
							<span class="wp-mcp-ai-mcp-app-status-dot"></span>
							<span class="wp-mcp-ai-mcp-app-status-text"><?php esc_html_e( 'Not tested', 'mcp-ai-wpoos' ); ?></span>
						</span>
					</span>
					<div>
						<label style="margin-right: 10px;">
							<input type="hidden" name="wp_mcp_ai_mcp_apps[{{data.index}}][enabled]" value="0" />
							<input type="checkbox" name="wp_mcp_ai_mcp_apps[{{data.index}}][enabled]" value="1" checked />
							<?php esc_html_e( 'Enabled', 'mcp-ai-wpoos' ); ?>
						</label>
						<button type="button" class="button button-link-delete wp-mcp-ai-remove-mcp-app"><?php esc_html_e( 'Remove', 'mcp-ai-wpoos' ); ?></button>
					</div>
				</div>

				<table class="form-table" style="margin: 0;">
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="text" name="wp_mcp_ai_mcp_apps[{{data.index}}][label]" value="" class="regular-text wp-mcp-ai-mcp-app-label" placeholder="<?php esc_attr_e( 'My MCP App', 'mcp-ai-wpoos' ); ?>" />
							<p class="description"><?php esc_html_e( 'A friendly name for this MCP App connection.', 'mcp-ai-wpoos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Server URL', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="url" name="wp_mcp_ai_mcp_apps[{{data.index}}][server_url]" value="" class="regular-text" placeholder="https://example.com/mcp" required />
							<p class="description"><?php esc_html_e( 'The remote MCP server endpoint URL (Streamable HTTP transport).', 'mcp-ai-wpoos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Authentication', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<select name="wp_mcp_ai_mcp_apps[{{data.index}}][auth_type]" class="wp-mcp-ai-mcp-app-auth-type">
								<option value="none"><?php esc_html_e( 'None', 'mcp-ai-wpoos' ); ?></option>
								<option value="bearer"><?php esc_html_e( 'Bearer Token', 'mcp-ai-wpoos' ); ?></option>
								<option value="basic"><?php esc_html_e( 'Basic Auth (User:Password)', 'mcp-ai-wpoos' ); ?></option>
								<option value="header"><?php esc_html_e( 'Custom Header', 'mcp-ai-wpoos' ); ?></option>
								<option value="oauth"><?php esc_html_e( 'OAuth 2.0 Web Login', 'mcp-ai-wpoos' ); ?></option>
							</select>
						</td>
					</tr>
					<tr class="wp-mcp-ai-mcp-app-token-row" style="display:none;">
						<th scope="row"><label><?php esc_html_e( 'Token / API Key', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="password" name="wp_mcp_ai_mcp_apps[{{data.index}}][token]" value="" class="regular-text wp-mcp-ai-mcp-app-token-input" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'For Basic Auth, enter user:password or a pre-encoded base64 credential.', 'mcp-ai-wpoos' ); ?></p>
						</td>
					</tr>
					<tr class="wp-mcp-ai-mcp-app-header-row" style="display:none;">
						<th scope="row"><label><?php esc_html_e( 'Header Name', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="text" name="wp_mcp_ai_mcp_apps[{{data.index}}][header_name]" value="" class="regular-text" placeholder="X-API-Key" />
						</td>
					</tr>
					<tr class="wp-mcp-ai-mcp-app-oauth-row" style="display:none;">
						<th scope="row"><label><?php esc_html_e( 'OAuth Login', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<p class="description">
								<?php esc_html_e( 'Click the button below to connect via browser-based OAuth 2.0 login.', 'mcp-ai-wpoos' ); ?>
							</p>
							<button type="button" class="button wp-mcp-ai-connect-oauth">
								<?php esc_html_e( 'Connect via Web Login', 'mcp-ai-wpoos' ); ?>
							</button>
							<button type="button" class="button wp-mcp-ai-reconnect-oauth" style="display:none;">
								<?php esc_html_e( 'Re-authenticate', 'mcp-ai-wpoos' ); ?>
							</button>
							<?php
							foreach ( array( 'access_token', 'refresh_token', 'token_type', 'expires_in', 'scope', 'issued_at' ) as $field ) {
								?>
								<input type="hidden" name="wp_mcp_ai_mcp_apps[{{data.index}}][oauth_data][<?php echo esc_attr( $field ); ?>]" value="" />
								<?php
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Timeout', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="number" name="wp_mcp_ai_mcp_apps[{{data.index}}][timeout]" value="30" min="1" max="120" style="width: 80px;" />
							<span class="description"><?php esc_html_e( 'seconds', 'mcp-ai-wpoos' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Verify SSL', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="hidden" name="wp_mcp_ai_mcp_apps[{{data.index}}][verify_ssl]" value="0" />
							<label>
								<input type="checkbox" name="wp_mcp_ai_mcp_apps[{{data.index}}][verify_ssl]" value="1" checked />
								<?php esc_html_e( 'Verify SSL certificate on the remote server.', 'mcp-ai-wpoos' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<div class="wp-mcp-ai-mcp-app-actions" style="margin-top: 12px;">
					<button type="button" class="button wp-mcp-ai-test-mcp-app">
						<span class="dashicons dashicons-admin-links" style="vertical-align: text-bottom;"></span>
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<button type="button" class="button wp-mcp-ai-discover-mcp-app">
						<span class="dashicons dashicons-search" style="vertical-align: text-bottom;"></span>
						<?php esc_html_e( 'Discover Tools', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="spinner wp-mcp-ai-mcp-app-spinner" style="display:none; float:none; margin-top:0;"></span>
				</div>

				<div class="wp-mcp-ai-mcp-app-result" style="display:none; margin-top: 10px;"></div>
			</div>
		</script>
		<?php
	}

		/**
		 * Render the JavaScript for the MCP Apps metabox.
		 *
		 * @since 1.8.0
		 * @since 1.9.1 Added Test Connection, Discover Tools, Test All, and JSON import.
		 * @return void
		 */
	protected function render_script() {
		$app_index        = (int) count( $this->get_current_apps_count() );
		$max_apps_message = esc_js( __( 'Maximum number of MCP Apps reached.', 'mcp-ai-wpoos' ) );
		$confirm_message  = esc_js( __( 'Remove this MCP App connection?', 'mcp-ai-wpoos' ) );
		$mcp_app_label    = esc_js( __( 'MCP App', 'mcp-ai-wpoos' ) );
		$lbl_connected    = esc_js( __( 'Connected', 'mcp-ai-wpoos' ) );
		$lbl_error        = esc_js( __( 'Error', 'mcp-ai-wpoos' ) );
		$lbl_not_tested   = esc_js( __( 'Not tested', 'mcp-ai-wpoos' ) );
		$lbl_tool         = esc_js( __( 'tool', 'mcp-ai-wpoos' ) );
		$lbl_tools        = esc_js( __( 'tools', 'mcp-ai-wpoos' ) );
		$lbl_url_required = esc_js( __( 'Please enter a Server URL first.', 'mcp-ai-wpoos' ) );
		$lbl_invalid_json = esc_js( __( 'The pasted text is not valid JSON. Expected a mcpServers block.', 'mcp-ai-wpoos' ) );
		$lbl_no_servers   = esc_js( __( 'No mcpServers entries found in the pasted JSON.', 'mcp-ai-wpoos' ) );
		$lbl_import_limit = esc_js( __( 'Importing these servers would exceed the maximum number of MCP Apps.', 'mcp-ai-wpoos' ) );
		$lbl_loopback     = esc_js( __( 'This server is on this WordPress site. Same-site REST endpoints are routed in-process to avoid TLS loopback deadlocks.', 'mcp-ai-wpoos' ) );

		ob_start();
		?>
				( function() {
					var appIndex = <?php echo (int) $app_index; ?>;
					var maxApps = 10;
					var restUrl = <?php echo wp_json_encode( esc_url_raw( rest_url( 'mcp-ai/v1/mcp-apps' ) ) ); ?>;
					var restNonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
					var siteHost = <?php echo wp_json_encode( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>;
					var lblConnected = <?php echo wp_json_encode( $lbl_connected ); ?>;
					var lblError = <?php echo wp_json_encode( $lbl_error ); ?>;
					var lblNotTested = <?php echo wp_json_encode( $lbl_not_tested ); ?>;
					var lblTool = <?php echo wp_json_encode( $lbl_tool ); ?>;
					var lblTools = <?php echo wp_json_encode( $lbl_tools ); ?>;
					var lblUrlRequired = <?php echo wp_json_encode( $lbl_url_required ); ?>;
					var lblInvalidJson = <?php echo wp_json_encode( $lbl_invalid_json ); ?>;
					var lblNoServers = <?php echo wp_json_encode( $lbl_no_servers ); ?>;
					var lblImportLimit = <?php echo wp_json_encode( $lbl_import_limit ); ?>;
					var lblLoopback = <?php echo wp_json_encode( $lbl_loopback ); ?>;

					function getAssistantId() {
						var urlParams = new URLSearchParams( window.location.search );
						return parseInt( urlParams.get( 'post' ) || '0', 10 ) || 0;
					}

					function readRowConfig( row ) {
						var urlInput = row.querySelector( 'input[type="url"]' );
						var authSelect = row.querySelector( '.wp-mcp-ai-mcp-app-auth-type' );
						var tokenInput = row.querySelector( 'input.wp-mcp-ai-mcp-app-token-input' );
						var headerInput = row.querySelector( 'input[name$="[header_name]"]' );
						var timeoutInput = row.querySelector( 'input[type="number"]' );
						var verifyInput = row.querySelector( 'input[name$="[verify_ssl]"][value="1"]' );

						return {
							server_url: ( urlInput ? urlInput.value.trim() : '' ),
							auth_type: ( authSelect ? authSelect.value : 'none' ),
							token: ( tokenInput ? tokenInput.value.trim() : '' ),
							header_name: ( headerInput ? headerInput.value.trim() : '' ),
							timeout: parseInt( ( timeoutInput ? timeoutInput.value : '30' ), 10 ) || 30,
							verify_ssl: ( verifyInput ? verifyInput.checked : true ),
							assistant_id: getAssistantId()
						};
					}

					function setRowBusy( row, busy ) {
						var spinner = row.querySelector( '.wp-mcp-ai-mcp-app-spinner' );
						if ( spinner ) {
							spinner.style.display = busy ? 'inline-block' : 'none';
						}
						row.querySelectorAll( '.wp-mcp-ai-mcp-app-actions button' ).forEach( function( btn ) {
							btn.disabled = busy;
						} );
					}

					function ajaxPost( path, body, onOk, onFail ) {
						var xhr = new XMLHttpRequest();
						xhr.open( 'POST', restUrl + path );
						xhr.setRequestHeader( 'Content-Type', 'application/json' );
						xhr.setRequestHeader( 'X-WP-Nonce', restNonce );
						xhr.onload = function() {
							var data = null;
							try {
								data = JSON.parse( xhr.responseText );
							} catch ( e ) {
								data = null;
							}
							if ( xhr.status >= 200 && xhr.status < 300 && data ) {
								onOk( data );
								return;
							}
							var msg = 'Request failed (HTTP ' + xhr.status + ').';
							if ( data && data.message ) {
								msg = data.message;
							} else if ( data && data.code ) {
								msg = data.code + ': ' + ( data.message || '' );
							}
							onFail( msg );
						};
						xhr.onerror = function() {
							onFail( 'Network error. Please check the server URL and try again.' );
						};
						xhr.send( JSON.stringify( body ) );
					}

					function renderResult( row, ok, lines ) {
						var resultEl = row.querySelector( '.wp-mcp-ai-mcp-app-result' );
						if ( ! resultEl ) {
							return;
						}

						resultEl.innerHTML = '';
						resultEl.style.display = '';
						resultEl.className = 'wp-mcp-ai-mcp-app-result notice inline ' + ( ok ? 'notice-success' : 'notice-error' );
						resultEl.style.margin = '10px 0 0';
						resultEl.style.padding = '10px';

						var summary = document.createElement( 'div' );
						summary.style.fontWeight = 'bold';
						summary.style.marginBottom = '6px';
						summary.textContent = ( ok ? '✓ ' : '✕ ' ) + ( lines[0] || '' );
						resultEl.appendChild( summary );

						for ( var i = 1; i < lines.length; i++ ) {
							var line = document.createElement( 'div' );
							line.textContent = lines[i];
							resultEl.appendChild( line );
						}
					}

					function updateBadge( row, state, toolCount, errorMessage ) {
						var badge = row.querySelector( '.wp-mcp-ai-mcp-app-status-badge' );
						if ( badge ) {
							badge.classList.remove( 'wp-mcp-ai-mcp-app-status-ok', 'wp-mcp-ai-mcp-app-status-error', 'wp-mcp-ai-mcp-app-status-unknown' );
							badge.classList.add( 'wp-mcp-ai-mcp-app-status-' + state );
							badge.title = errorMessage || '';
							var textEl = badge.querySelector( '.wp-mcp-ai-mcp-app-status-text' );
							if ( textEl ) {
								textEl.textContent = state === 'ok' ? lblConnected : ( state === 'error' ? lblError : lblNotTested );
							}
						}

						var chip = row.querySelector( '.wp-mcp-ai-mcp-app-tool-count' );
						if ( toolCount !== null && toolCount !== undefined && toolCount >= 0 ) {
							if ( ! chip && badge ) {
								chip = document.createElement( 'span' );
								chip.className = 'wp-mcp-ai-mcp-app-tool-count';
								badge.parentNode.insertBefore( chip, badge.nextSibling );
							}
							if ( chip ) {
								chip.textContent = toolCount + ' ' + ( toolCount === 1 ? lblTool : lblTools );
							}
						} else if ( chip ) {
							chip.remove();
						}
					}

					function runTest( row ) {
						var cfg = readRowConfig( row );
						if ( ! cfg.server_url ) {
							renderResult( row, false, [ lblUrlRequired ] );
							return;
						}

						setRowBusy( row, true );
						ajaxPost( '/test', cfg, function( data ) {
							setRowBusy( row, false );
							var lines = [];
							if ( data.success ) {
								var server = ( data.server_info && data.server_info.name ) ? data.server_info.name : '';
								var version = ( data.server_info && data.server_info.version ) ? ' ' + data.server_info.version : '';
								lines.push( lblConnected + ( server ? ' — ' + server + version : '' ) );
								lines.push( 'Protocol: ' + ( data.protocol || 'unknown' ) + ' · Handshake: ' + ( data.handshake || 'unknown' ) + ( data.session_active ? ' · session: active' : '' ) );
								lines.push( 'Latency: ' + ( data.latency_ms || 0 ) + ' ms' );
								if ( data.tool_count !== null && data.tool_count !== undefined ) {
									lines.push( 'Tools enumerated: ' + data.tool_count );
								}
								if ( data.tool_error ) {
									lines.push( 'Tool enumeration failed: ' + data.tool_error );
								}
								if ( data.same_origin ) {
									lines.push( '⚠ ' + lblLoopback );
								}
								updateBadge( row, 'ok', data.tool_count, '' );
							} else {
								lines.push( data.message || lblError );
								updateBadge( row, 'error', null, data.message || '' );
							}
							renderResult( row, data.success, lines );
						}, function( message ) {
							setRowBusy( row, false );
							renderResult( row, false, [ message ] );
							updateBadge( row, 'error', null, message );
						} );
					}

					function runDiscover( row ) {
						var cfg = readRowConfig( row );
						if ( ! cfg.server_url ) {
							renderResult( row, false, [ lblUrlRequired ] );
							return;
						}
						cfg.refresh = true;

						setRowBusy( row, true );
						ajaxPost( '/discover', cfg, function( data ) {
							setRowBusy( row, false );
							var lines = [];
							if ( data.success ) {
								lines.push( 'Discovered ' + data.tool_count + ' ' + ( data.tool_count === 1 ? lblTool : lblTools ) + '.' );
								var tools = data.tools || [];
								tools.slice( 0, 25 ).forEach( function( tool ) {
									lines.push( '– ' + tool.name + ( tool.has_ui ? ' · UI' : '' ) );
								} );
								if ( tools.length > 25 ) {
									lines.push( '… and ' + ( tools.length - 25 ) + ' more' );
								}
								updateBadge( row, 'ok', data.tool_count, '' );
							} else {
								lines.push( data.message || lblError );
								updateBadge( row, 'error', null, data.message || '' );
							}
							renderResult( row, data.success, lines );
						}, function( message ) {
							setRowBusy( row, false );
							renderResult( row, false, [ message ] );
							updateBadge( row, 'error', null, message );
						} );
					}

					function appendNewRow() {
						var tmpl = document.getElementById( 'tmpl-wp-mcp-ai-mcp-app-row' );
						if ( ! tmpl ) {
							return null;
						}

						var html = tmpl.innerHTML.replace( /\{\{data\.index\}\}/g, appIndex );
						appIndex++;

						var wrapper = document.createElement( 'div' );
						wrapper.innerHTML = html;
						return wrapper.firstElementChild;
					}

					function mapAuth( server ) {
						var headers = server.headers || {};
						var authz = typeof headers.Authorization === 'string' ? headers.Authorization : ( typeof headers.authorization === 'string' ? headers.authorization : '' );

						if ( authz.indexOf( 'Basic ' ) === 0 ) {
							return { auth_type: 'basic', token: authz.substring( 6 ).trim(), header_name: '' };
						}
						if ( authz.indexOf( 'Bearer ' ) === 0 ) {
							return { auth_type: 'bearer', token: authz.substring( 7 ).trim(), header_name: '' };
						}
						if ( authz !== '' ) {
							return { auth_type: 'header', token: authz, header_name: 'Authorization' };
						}

						var keys = Object.keys( headers );
						if ( keys.length ) {
							return { auth_type: 'header', token: headers[ keys[0] ], header_name: keys[0] };
						}

						return { auth_type: 'none', token: '', header_name: '' };
					}

					function fillRowFromImport( row, name, server ) {
						var auth = mapAuth( server );

						var labelInput = row.querySelector( '.wp-mcp-ai-mcp-app-label' );
						var urlInput = row.querySelector( 'input[type="url"]' );
						var authSelect = row.querySelector( '.wp-mcp-ai-mcp-app-auth-type' );
						var tokenInput = row.querySelector( 'input.wp-mcp-ai-mcp-app-token-input' );
						var headerInput = row.querySelector( 'input[name$="[header_name]"]' );

						if ( labelInput ) {
							labelInput.value = name || '';
						}
						if ( urlInput ) {
							urlInput.value = server.url || '';
						}
						if ( authSelect ) {
							authSelect.value = auth.auth_type;
							authSelect.dispatchEvent( new Event( 'change' ) );
						}
						if ( tokenInput ) {
							tokenInput.value = auth.token || '';
						}
						if ( headerInput ) {
							headerInput.value = auth.header_name || '';
						}

						var titleEl = row.querySelector( '.wp-mcp-ai-mcp-app-title' );
						if ( titleEl ) {
							titleEl.textContent = name || <?php echo wp_json_encode( $mcp_app_label ); ?>;
						}
					}

					document.addEventListener( 'DOMContentLoaded', function() {
						var addBtn = document.getElementById( 'wp-mcp-ai-add-mcp-app' );
						var testAllBtn = document.getElementById( 'wp-mcp-ai-test-all-mcp-apps' );
						var importBtn = document.getElementById( 'wp-mcp-ai-import-mcp-apps' );
						var importPanel = document.getElementById( 'wp-mcp-ai-import-mcp-apps-panel' );
						var importApplyBtn = document.getElementById( 'wp-mcp-ai-import-mcp-apps-apply' );
						var importCancelBtn = document.getElementById( 'wp-mcp-ai-import-mcp-apps-cancel' );
						var importJsonEl = document.getElementById( 'wp-mcp-ai-import-mcp-apps-json' );
						var listEl = document.getElementById( 'wp-mcp-ai-mcp-apps-list' );
						var emptyEl = document.getElementById( 'wp-mcp-ai-mcp-apps-empty' );

						if ( ! addBtn || ! listEl ) {
							return;
						}

						addBtn.addEventListener( 'click', function() {
							var rows = listEl.querySelectorAll( '.wp-mcp-ai-mcp-app-row' );
							if ( rows.length >= maxApps ) {
								window.alert( <?php echo wp_json_encode( $max_apps_message ); ?> );
								return;
							}

							var row = appendNewRow();
							if ( ! row ) {
								return;
							}

							if ( emptyEl ) {
								emptyEl.style.display = 'none';
							}

							listEl.appendChild( row );
						} );

						if ( testAllBtn ) {
							testAllBtn.addEventListener( 'click', function() {
								listEl.querySelectorAll( '.wp-mcp-ai-mcp-app-row' ).forEach( function( row ) {
									runTest( row );
								} );
							} );
						}

						if ( importBtn && importPanel ) {
							importBtn.addEventListener( 'click', function() {
								importPanel.style.display = importPanel.style.display === 'none' ? '' : 'none';
							} );
						}

						if ( importCancelBtn && importPanel ) {
							importCancelBtn.addEventListener( 'click', function() {
								importPanel.style.display = 'none';
								if ( importJsonEl ) {
									importJsonEl.value = '';
								}
							} );
						}

						if ( importApplyBtn && importJsonEl ) {
							importApplyBtn.addEventListener( 'click', function() {
								var text = importJsonEl.value.trim();
								if ( ! text ) {
									return;
								}

								var parsed = null;
								try {
									parsed = JSON.parse( text );
								} catch ( e ) {
									parsed = null;
								}

								if ( ! parsed ) {
									window.alert( <?php echo wp_json_encode( $lbl_invalid_json ); ?> );
									return;
								}

								var servers = parsed.mcpServers;
								if ( ! servers && parsed.url ) {
									servers = { imported: parsed };
								}
								if ( ! servers ) {
									window.alert( <?php echo wp_json_encode( $lbl_no_servers ); ?> );
									return;
								}

								var names = Object.keys( servers );
								var currentRows = listEl.querySelectorAll( '.wp-mcp-ai-mcp-app-row' );
								if ( currentRows.length + names.length > maxApps ) {
									window.alert( <?php echo wp_json_encode( $lbl_import_limit ); ?> );
									return;
								}

								names.forEach( function( name ) {
									var row = appendNewRow();
									if ( row ) {
										// Attach before filling so delegated change
										// handlers (auth type row visibility) fire.
										listEl.appendChild( row );
										fillRowFromImport( row, name, servers[ name ] );
									}
								} );

								if ( emptyEl ) {
									emptyEl.style.display = 'none';
								}

								importPanel.style.display = 'none';
								importJsonEl.value = '';
							} );
						}

						listEl.addEventListener( 'click', function( event ) {
								if ( event.target.closest( '.wp-mcp-ai-remove-mcp-app' ) ) {
									if ( window.confirm( <?php echo wp_json_encode( $confirm_message ); ?> ) ) {
										var row = event.target.closest( '.wp-mcp-ai-mcp-app-row' );
										if ( row ) {
											row.remove();
										}

										var remaining = listEl.querySelectorAll( '.wp-mcp-ai-mcp-app-row' );
										if ( remaining.length === 0 && emptyEl ) {
											emptyEl.style.display = '';
										}
									}
									return;
								}

								if ( event.target.closest( '.wp-mcp-ai-test-mcp-app' ) ) {
									event.preventDefault();
									var row = event.target.closest( '.wp-mcp-ai-mcp-app-row' );
									if ( row ) {
										runTest( row );
									}
									return;
								}

								if ( event.target.closest( '.wp-mcp-ai-discover-mcp-app' ) ) {
									event.preventDefault();
									var discoverRow = event.target.closest( '.wp-mcp-ai-mcp-app-row' );
									if ( discoverRow ) {
										runDiscover( discoverRow );
									}
									return;
								}

								if ( event.target.closest( '.wp-mcp-ai-connect-oauth' ) || event.target.closest( '.wp-mcp-ai-reconnect-oauth' ) ) {
									event.preventDefault();
									var btn = event.target.closest( '.wp-mcp-ai-connect-oauth' ) || event.target.closest( '.wp-mcp-ai-reconnect-oauth' );
									var serverUrl = btn.getAttribute( 'data-server-url' ) || '';
									var row = btn.closest( '.wp-mcp-ai-mcp-app-row' );

									// Read the server URL from the row's input field if data attribute is empty (new rows).
									if ( ! serverUrl && row ) {
										var urlInput = row.querySelector( 'input[type="url"]' );
										if ( urlInput ) {
											serverUrl = urlInput.value.trim();
										}
									}

									if ( ! serverUrl ) {
										window.alert( <?php echo wp_json_encode( $lbl_url_required ); ?> );
										return;
									}

									// Disable button while requesting.
									btn.disabled = true;
									btn.textContent = 'Connecting…';

									var xhr = new XMLHttpRequest();
									xhr.open( 'POST', '<?php echo esc_url_raw( rest_url( 'mcp-ai/v1/mcp-apps/oauth/init' ) ); ?>' );
									xhr.setRequestHeader( 'Content-Type', 'application/json' );
									xhr.setRequestHeader( 'X-WP-Nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> );
									xhr.onload = function() {
										if ( xhr.status === 200 ) {
											var data = JSON.parse( xhr.responseText );
											if ( data.authorization_url ) {
												window.location.href = data.authorization_url;
											} else {
												window.alert( 'Failed to start OAuth flow.' );
												btn.disabled = false;
												btn.textContent = btn.classList.contains( 'wp-mcp-ai-reconnect-oauth' ) ? 'Re-authenticate' : 'Connect via Web Login';
											}
										} else {
											window.alert( 'OAuth initiation failed. Check the server URL and try again.' );
											btn.disabled = false;
											btn.textContent = btn.classList.contains( 'wp-mcp-ai-reconnect-oauth' ) ? 'Re-authenticate' : 'Connect via Web Login';
										}
									};
									xhr.onerror = function() {
										window.alert( 'Network error. Please check the server URL and try again.' );
										btn.disabled = false;
										btn.textContent = btn.classList.contains( 'wp-mcp-ai-reconnect-oauth' ) ? 'Re-authenticate' : 'Connect via Web Login';
									};
									xhr.send( JSON.stringify( { server_url: serverUrl, assistant_id: getAssistantId() } ) );
								}
							} );

						listEl.addEventListener( 'change', function( event ) {
							if ( event.target.classList.contains( 'wp-mcp-ai-mcp-app-auth-type' ) ) {
								var row = event.target.closest( '.wp-mcp-ai-mcp-app-row' );
								if ( ! row ) {
									return;
								}

								var tokenRow = row.querySelector( '.wp-mcp-ai-mcp-app-token-row' );
								var headerRow = row.querySelector( '.wp-mcp-ai-mcp-app-header-row' );
								var oauthRow = row.querySelector( '.wp-mcp-ai-mcp-app-oauth-row' );
								var value = event.target.value;

								if ( tokenRow ) {
									tokenRow.style.display = ( value === 'none' || value === 'oauth' ) ? 'none' : '';
								}
								if ( headerRow ) {
									headerRow.style.display = ( value === 'header' ) ? '' : 'none';
								}
								if ( oauthRow ) {
									oauthRow.style.display = ( value === 'oauth' ) ? '' : 'none';
								}
							}
						} );

						listEl.addEventListener( 'input', function( event ) {
							if ( event.target.classList.contains( 'wp-mcp-ai-mcp-app-label' ) ) {
								var row = event.target.closest( '.wp-mcp-ai-mcp-app-row' );
								if ( ! row ) {
									return;
								}

								var titleEl = row.querySelector( '.wp-mcp-ai-mcp-app-title' );
								if ( titleEl ) {
									titleEl.textContent = event.target.value || <?php echo wp_json_encode( $mcp_app_label ); ?>;
								}
							}
						} );
				} );
			} )();
			<?php
			$js = ob_get_clean();
			wp_print_inline_script_tag( $js );
	}

	/**
	 * Get current apps count for JS initialization.
	 *
	 * @since 1.8.0
	 * @return array
	 */
	protected function get_current_apps_count() {
		global $post;

		if ( ! $post || ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			return array();
		}

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		return $registry->get_apps( $post->ID );
	}
}
