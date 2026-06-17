<?php
/**
 * Chat Channels Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Chat Channels Toolkit Settings Page Class
 */
class WP_MCP_AI_Chat_Channels_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'chat_channels';
		$this->toolkit_name     = __( 'Chat Channels Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_chat_channels_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-chat-channels-toolkit-settings';
		$this->has_research     = false;
		$this->has_remote_sites = false;
		$this->icon             = 'dashicons-format-chat';

		parent::__construct();

		add_action( 'wp_ajax_wp_mcp_ai_fetch_whatsapp_phone_numbers', array( $this, 'ajax_fetch_whatsapp_phone_numbers' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_tma_builder_assets' ) );
	}

	/**
	 * AJAX handler: Fetch WhatsApp phone numbers from the Facebook Graph API.
	 *
	 * Calls https://graph.facebook.com/v22.0/{waba_id}/phone_numbers using the
	 * provided system user access token and returns the list of phone numbers.
	 */
	public function ajax_fetch_whatsapp_phone_numbers() {
		check_ajax_referer( 'wp_mcp_ai_fetch_whatsapp_phone_numbers', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		$business_account_id = isset( $_POST['business_account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['business_account_id'] ) ) : '';
		$access_token        = isset( $_POST['access_token'] ) ? wp_unslash( $_POST['access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- access tokens must not be sanitized as sanitize_text_field() can truncate valid token characters.
		$access_token        = trim( (string) $access_token );

		if ( empty( $business_account_id ) || empty( $access_token ) ) {
			wp_send_json_error( array( 'message' => __( 'Business Account ID and Access Token are required.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		$api_url = add_query_arg(
			array( 'fields' => 'id,display_phone_number,verified_name' ),
			'https://graph.facebook.com/v22.0/' . rawurlencode( $business_account_id ) . '/phone_numbers'
		);

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'Connection to the Facebook Graph API failed. Please check your network and try again.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) || isset( $data['error'] ) ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Failed to retrieve phone numbers.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error( array( 'message' => $error_message ) );
			return;
		}

		$phone_numbers = array();
		if ( ! empty( $data['data'] ) && is_array( $data['data'] ) ) {
			foreach ( $data['data'] as $phone ) {
				$phone_numbers[] = array(
					'id'            => isset( $phone['id'] ) ? sanitize_text_field( $phone['id'] ) : '',
					'display_name'  => isset( $phone['display_phone_number'] ) ? sanitize_text_field( $phone['display_phone_number'] ) : '',
					'verified_name' => isset( $phone['verified_name'] ) ? sanitize_text_field( $phone['verified_name'] ) : '',
				);
			}
		}

		if ( empty( $phone_numbers ) ) {
			wp_send_json_error( array( 'message' => __( 'No phone numbers found for this Business Account ID.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		wp_send_json_success( array( 'phone_numbers' => $phone_numbers ) );
	}

	/**
	 * Enqueue TMA Template Builder React assets when on the Mini App Builder tab.
	 *
	 * The compiled assets (JS + CSS) are bundled with the plugin under
	 * addons/pro/build/tma-template-builder/ so no npm build step is needed
	 * after installation.
	 *
	 * @since 1.1.3
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_tma_builder_assets( $hook ) {
		// Only enqueue on this settings page.
		if ( ! str_contains( $hook, $this->page_slug ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		if ( 'mini_app_builder' !== $active_tab ) {
			return;
		}

		$build_dir = WP_MCP_AI_PRO_PATH . 'build/tma-template-builder/';
		$build_url = WP_MCP_AI_PRO_URL . 'build/tma-template-builder/';
		$js_file   = $build_dir . 'tma-template-builder.js';
		$css_file  = $build_dir . 'tma-template-builder.css';

		if ( ! file_exists( $js_file ) ) {
			// Bundle should always be present; log for debugging if missing.
			error_log( 'WP_MCP_AI: TMA Template Builder compiled assets not found. Re-activate the plugin or contact support.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		/*
		 * The TMA Template Builder bundle is produced by webpack.config.tma.js,
		 * which intentionally strips the DependencyExtractionWebpackPlugin so
		 * that React, ReactDOM and the @wordpress/element wrapper are bundled
		 * directly into the standalone IIFE. As a result, no companion
		 * `*.asset.php` manifest is emitted. We therefore declare an empty
		 * dependency array and derive a cache-busting version from filemtime,
		 * falling back to the plugin's version constant when filemtime fails
		 * (e.g. permission issues or stat races).
		 */
		$asset_file = $build_dir . 'tma-template-builder.asset.php';
		if ( file_exists( $asset_file ) ) {
			$asset           = require $asset_file;
			$js_dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
			$asset_version   = isset( $asset['version'] ) ? $asset['version'] : null;
		} else {
			$js_dependencies = array();
			$asset_version   = null;
		}

		if ( empty( $asset_version ) ) {
			$mtime         = @filemtime( $js_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$asset_version = $mtime ? (string) $mtime : ( defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : false );
		}

		wp_enqueue_script(
			'mcp-ai-tma-template-builder',
			$build_url . 'tma-template-builder.js',
			$js_dependencies,
			$asset_version,
			true
		);

		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'mcp-ai-tma-template-builder',
				$build_url . 'tma-template-builder.css',
				array(),
				$asset_version
			);
		}

		// Localize data for the React component.
		wp_localize_script(
			'mcp-ai-tma-template-builder',
			'mcpAiTmaBuilder',
			array(
				'templatesUrl'   => rest_url( 'mcp-ai/v1/telegram-mini-app/templates' ),
				'saveUrl'        => rest_url( 'mcp-ai/v1/telegram-mini-app/template' ),
				'previewBaseUrl' => rest_url( 'mcp-ai/v1/telegram-mini-app' ),
				'activeTemplate' => get_option( 'wp_mcp_ai_telegram_mini_app_template', 'default' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
	/**
	 * Override tab navigation to add the Mini App Builder tab.
	 *
	 * @since 1.1.3
	 *
	 * @param string $active_tab Active tab slug.
	 */
	protected function render_tabs( $active_tab ) {
		$tabs = array(
			'overview'         => __( 'Overview', 'mcp-ai-wpoos-pro' ),
			'configuration'    => __( 'Configuration', 'mcp-ai-wpoos-pro' ),
			'mini_app_builder' => __( '📱 Mini App Builder', 'mcp-ai-wpoos-pro' ),
			'tools'            => __( 'Tools Management', 'mcp-ai-wpoos-pro' ),
			'help'             => __( 'Help & Documentation', 'mcp-ai-wpoos-pro' ),
		);
		?>
		<nav class="toolkit-settings-nav nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, admin_url( 'admin.php?page=' . $this->page_slug ) ) ); ?>"
					class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded CSS class. ?>"
				>
					<?php echo esc_html( $tab_title ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Override render_settings_page to intercept the Mini App Builder tab.
	 *
	 * All other tabs delegate to the parent implementation so nothing changes
	 * for the existing Overview / Configuration / Tools / Help tabs.
	 *
	 * @since 1.1.3
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';

		if ( 'mini_app_builder' !== $active_tab ) {
			parent::render_settings_page();
			return;
		}

		?>
		<div class="wrap">
			<h1>
				<span class="dashicons <?php echo esc_attr( $this->icon ); ?>" style="font-size: 32px;"></span>
				<?php echo esc_html( $this->toolkit_name . ' ' . __( 'Settings', 'mcp-ai-wpoos-pro' ) ); ?>
			</h1>

			<?php $this->render_tabs( $active_tab ); ?>

			<div class="toolkit-settings-content">
				<?php $this->render_mini_app_builder_tab(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Mini App Builder tab content.
	 *
	 * Mounts the pre-built React TMATemplateBuilder component. The compiled
	 * assets are bundled with the plugin (addons/pro/build/tma-template-builder/)
	 * so no additional build steps are needed after installation.
	 *
	 * A static <noscript> form is always rendered below the React mount point so
	 * that the global template can be saved even without JavaScript.
	 *
	 * @since 1.1.3
	 */
	protected function render_mini_app_builder_tab() {
		// Load template registry for the static no-JS form.
		if ( ! class_exists( 'WP_MCP_AI_Telegram_Mini_App_Template_Registry' ) ) {
			$_tpl_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-mini-app-templates.php';
			if ( file_exists( $_tpl_file ) ) {
				require_once $_tpl_file;
			}
		}

		$all_templates    = class_exists( 'WP_MCP_AI_Telegram_Mini_App_Template_Registry' )
			? WP_MCP_AI_Telegram_Mini_App_Template_Registry::get_all_meta()
			: array();
		$active_slug      = get_option( 'wp_mcp_ai_telegram_mini_app_template', 'default' );
		$templates_url    = rest_url( 'mcp-ai/v1/telegram-mini-app/templates' );
		$save_url         = rest_url( 'mcp-ai/v1/telegram-mini-app/template' );
		$preview_url      = rest_url( 'mcp-ai/v1/telegram-mini-app' );
		$pro_settings_url = admin_url( 'admin.php?page=nvoos-pro-settings' );
		?>
		<div class="toolkit-card" style="padding: 0; overflow: hidden;">

			<!-- Tab header -->
			<div style="background: linear-gradient(135deg, #1565c0 0%, #2481cc 100%); padding: 24px 28px; color: #fff;">
				<h2 style="margin: 0 0 8px 0; color: #fff; font-size: 22px;">
					📱 <?php esc_html_e( 'Mini App Template Builder', 'mcp-ai-wpoos-pro' ); ?>
				</h2>
				<p style="margin: 0; opacity: 0.88; font-size: 14px;">
					<?php esc_html_e( 'Choose a pre-built template for your Telegram Mini App. Each template is optimized for a specific Pro toolkit. Individual bot connections can override this global default.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
			</div>

			<div style="padding: 24px 28px;">

				<?php /* React mount point — TMATemplateBuilder renders here (assets are bundled with the plugin). */ ?>
				<div
					id="mcp-ai-tma-template-builder-root"
					data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
					data-templates-url="<?php echo esc_url( $templates_url ); ?>"
					data-save-url="<?php echo esc_url( $save_url ); ?>"
					data-active-template="<?php echo esc_attr( $active_slug ); ?>"
					data-preview-base-url="<?php echo esc_url( $preview_url ); ?>"
					data-customize-url="<?php echo esc_url( rest_url( 'mcp-ai/v1/telegram-mini-app/template' ) ); ?>"
				></div>

				<?php /* Static no-JS fallback: hidden when React renders, used for non-JS saves. */ ?>
				<noscript>
				<form method="post" action="options.php" id="tma-global-template-form">
					<?php settings_fields( $this->option_name . '_group' ); ?>
					<input type="hidden" name="<?php echo esc_attr( $this->option_name ); ?>[placeholder]" value="1" />

					<h3><?php esc_html_e( 'Global Default Template', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p class="description" style="margin-bottom: 16px;">
						<?php esc_html_e( 'This template is used when a bot connection does not specify its own override.', 'mcp-ai-wpoos-pro' ); ?>
					</p>

					<table class="form-table" style="margin-bottom: 0;">
						<tr>
							<th scope="row">
								<label for="tma-global-template-select"><?php esc_html_e( 'Active Template', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<select id="tma-global-template-select" name="wp_mcp_ai_telegram_mini_app_template">
									<?php foreach ( $all_templates as $tpl ) : ?>
										<option value="<?php echo esc_attr( $tpl['slug'] ); ?>" <?php selected( $active_slug, $tpl['slug'] ); ?>>
											<?php echo esc_html( $tpl['icon'] . ' ' . $tpl['name'] ); ?>
											<?php if ( $tpl['toolkit'] ) : ?>
												(<?php echo esc_html( str_replace( '_', ' ', $tpl['toolkit'] ) ); ?>)
											<?php endif; ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Per-connection overrides are set in the Remote Site Manager → Telegram connection edit form.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Template', 'mcp-ai-wpoos-pro' ) ); ?>
				</form>
				</noscript>

				<hr style="margin: 28px 0;">
				<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">

					<div style="padding: 16px; background: #f0f6fc; border: 1px solid #2271b1; border-radius: 6px;">
						<h3 style="margin: 0 0 10px 0; color: #2271b1;">
							<span class="dashicons dashicons-info"></span>
							<?php esc_html_e( 'Per-Connection Override', 'mcp-ai-wpoos-pro' ); ?>
						</h3>
						<p style="font-size: 13px; margin: 0 0 12px 0;">
							<?php esc_html_e( 'Each Telegram bot connection can use a different template independent of the global default set above.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Open Remote Site Manager →', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</div>

					<div style="padding: 16px; background: #f0f9f4; border: 1px solid #00a32a; border-radius: 6px;">
						<h3 style="margin: 0 0 10px 0; color: #00a32a;">
							<span class="dashicons dashicons-admin-settings"></span>
							<?php esc_html_e( 'Full System Info', 'mcp-ai-wpoos-pro' ); ?>
						</h3>
						<p style="font-size: 13px; margin: 0 0 12px 0;">
							<?php esc_html_e( 'View all template details and package information on the Pro Settings page.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
						<a href="<?php echo esc_url( $pro_settings_url ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Pro Settings →', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</div>

				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get toolkit slug
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Override the configuration form to include the Global Settings section
	 * inside the WordPress Settings API form so values are properly saved.
	 */
	protected function render_configuration_form() {
		$settings = get_option( $this->option_name, array() );

		$saved_assistant        = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
		$enable_logging         = ! empty( $settings['enable_logging'] );
		$enable_rate_limiting   = isset( $settings['enable_rate_limiting'] ) ? (bool) $settings['enable_rate_limiting'] : true;
		$verify_webhook         = isset( $settings['verify_webhook_signatures'] ) ? (bool) $settings['verify_webhook_signatures'] : true;
		$message_retention_days = isset( $settings['optimization']['message_retention_days'] ) ? absint( $settings['optimization']['message_retention_days'] ) : 90;

		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( $this->option_name . '_group' ); ?>
				<?php do_settings_sections( $this->option_name ); ?>

				<h2 style="margin-top: 20px;"><?php esc_html_e( 'Global Settings', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="cc-default-assistant-setting"><?php esc_html_e( 'Default AI Assistant', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<select id="cc-default-assistant-setting" name="<?php echo esc_attr( $this->option_name ); ?>[default_assistant]" class="regular-text">
								<option value=""><?php esc_html_e( '-- Select Assistant --', 'mcp-ai-wpoos-pro' ); ?></option>
								<?php foreach ( $assistants as $assistant ) : ?>
									<option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $saved_assistant, $assistant->ID ); ?>>
										<?php echo esc_html( $assistant->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default AI assistant for handling chat channel messages (used by the Telegram Mini App and other channels).', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Logging', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_logging]" value="1" <?php checked( $enable_logging ); ?> />
								<?php esc_html_e( 'Log all chat channel activities for debugging', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rate Limiting', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_rate_limiting]" value="1" <?php checked( $enable_rate_limiting ); ?> />
								<?php esc_html_e( 'Enable automatic rate limiting to prevent API quota exhaustion', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook Security', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[verify_webhook_signatures]" value="1" <?php checked( $verify_webhook ); ?> />
								<?php esc_html_e( 'Verify webhook signatures (recommended for security)', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</td>
					</tr>
				</table>

					<h2 style="margin-top: 20px;"><?php esc_html_e( 'Performance &amp; Storage', 'mcp-ai-wpoos-pro' ); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="cc-retention-days"><?php esc_html_e( 'Message Retention (days)', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<input type="number"
									id="cc-retention-days"
									name="<?php echo esc_attr( $this->option_name ); ?>[optimization][message_retention_days]"
									value="<?php echo esc_attr( $message_retention_days ); ?>"
									min="0" max="730" class="small-text" />
								<p class="description">
									<?php esc_html_e( 'Number of days to retain chat channel messages. Older messages are pruned daily at 2 AM. Set to 0 to keep forever. Default: 90.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							</td>
						</tr>
					</table>

				<?php submit_button( __( 'Save Settings', 'mcp-ai-wpoos-pro' ) ); ?>
			</form>
		</div><!-- .toolkit-card -->
		<?php
		$this->render_configuration_tab();
	}

	/**
	 * Override sanitize_settings to include the Global Settings fields.
	 *
	 * @param array $input Settings input from the form.
	 * @return array Sanitized settings array.
	 */
	public function sanitize_settings( $input ) {
		// Call the parent to handle any base-class fields (enable_remote_sites, enable_research,
		// research_assistant_id) – these are disabled for this toolkit but calling the parent
		// is harmless and future-proofs the code if they are ever enabled.
		$sanitized = parent::sanitize_settings( $input );

		// Chat Channels Global Settings fields.
		$sanitized['default_assistant']         = isset( $input['default_assistant'] ) ? absint( $input['default_assistant'] ) : 0;
		$sanitized['enable_logging']            = ! empty( $input['enable_logging'] );
		$sanitized['enable_rate_limiting']      = ! empty( $input['enable_rate_limiting'] );
		$sanitized['verify_webhook_signatures'] = ! empty( $input['verify_webhook_signatures'] );

		// Performance & Storage fields.
		$sanitized['optimization']                           = isset( $sanitized['optimization'] ) && is_array( $sanitized['optimization'] )
			? $sanitized['optimization']
			: array();
		$sanitized['optimization']['message_retention_days'] = isset( $input['optimization']['message_retention_days'] )
			? max( 0, min( 730, absint( $input['optimization']['message_retention_days'] ) ) )
			: 90;

		return $sanitized;
	}

	/**
	 * Render overview tab
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'Chat Channels Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Enterprise-grade chat channel integration toolkit with specialized tools for managing communications across Telegram, WhatsApp, Slack, Discord, Microsoft Teams, Facebook Messenger, Office 365 (Outlook, OneDrive), and iCloud Drive.', 'mcp-ai-wpoos-pro' ); ?></p>
				<p><strong><?php esc_html_e( 'Built with research from OpenClaw.ai\'s extensive multi-platform chat integration experience.', 'mcp-ai-wpoos-pro' ); ?></strong></p>
			</div>

			<h3><?php esc_html_e( 'Key Capabilities', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Unified Message Management: Send, receive, and manage messages across all platforms from one interface', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Multi-Platform Broadcasting: Send the same message to multiple channels simultaneously', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Rich Media Support: Handle text, images, videos, documents, and interactive elements', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'User & Group Management: Create, manage, and organize channels, groups, and user permissions', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Real-Time Analytics: Track message delivery, engagement, and user activity across platforms', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Webhook Integration: Receive real-time events and updates from all platforms', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'AI-Powered Responses: Leverage AI assistants to provide automated, context-aware responses', 'mcp-ai-wpoos-pro' ); ?></li>
				<li>
					<strong>📱 <?php esc_html_e( 'Mini App Template Builder:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php esc_html_e( '6 pre-built Telegram Mini App templates, drag-to-reorder card picker, live iframe preview, and per-connection overrides', 'mcp-ai-wpoos-pro' ); ?>
					— <a href="<?php echo esc_url( add_query_arg( 'tab', 'mini_app_builder', admin_url( 'admin.php?page=' . $this->page_slug ) ) ); ?>"><?php esc_html_e( 'Open Mini App Builder →', 'mcp-ai-wpoos-pro' ); ?></a>
				</li>
			</ul>

			<h3><?php esc_html_e( 'Supported Platforms', 'mcp-ai-wpoos-pro' ); ?></h3>
			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;">
				<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
					<h4 style="margin-top: 0;">📱 Telegram</h4>
					<p><?php esc_html_e( 'Full Bot API support with rich media, inline keyboards, and callback queries', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
					<h4 style="margin-top: 0;">💬 WhatsApp</h4>
					<p><?php esc_html_e( 'Business API integration with template messages and interactive buttons', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
					<h4 style="margin-top: 0;">💼 Slack</h4>
					<p><?php esc_html_e( 'Workspace integration with channels, threads, and interactive components', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
					<h4 style="margin-top: 0;">🎮 Discord</h4>
					<p><?php esc_html_e( 'Server management with channels, roles, and embedded messages', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
					<h4 style="margin-top: 0;">👔 Microsoft Teams</h4>
					<p><?php esc_html_e( 'Enterprise integration with channels, tabs, and adaptive cards', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
					<h4 style="margin-top: 0;">📘 Facebook Messenger</h4>
					<p><?php esc_html_e( 'Page messaging with quick replies and persistent menus', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
					<h4 style="margin-top: 0;">📧 Office 365</h4>
					<p><?php esc_html_e( 'Outlook mail, OneDrive files, and Office document integration via Microsoft Graph API', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
					<h4 style="margin-top: 0;">☁️ iCloud</h4>
					<p><?php esc_html_e( 'iCloud Drive file management via a configurable gateway service', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			</div>

			<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Customer Support:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Provide 24/7 support across multiple chat platforms', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Marketing Automation:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Send targeted campaigns and promotional messages', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Community Management:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Manage large communities with automated moderation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Internal Communications:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Coordinate teams across different platforms', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Lead Generation:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Capture and qualify leads through chat interactions', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Platform Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Configure API credentials and settings for each platform below. Click on each platform to expand configuration options.', 'mcp-ai-wpoos-pro' ); ?></p>

			<?php $this->render_telegram_config(); ?>
			<?php $this->render_whatsapp_config(); ?>
			<?php $this->render_slack_config(); ?>
			<?php $this->render_discord_config(); ?>
			<?php $this->render_teams_config(); ?>
			<?php $this->render_office365_config(); ?>
			<?php $this->render_messenger_config(); ?>
			<?php $this->render_twitter_config(); ?>
			<?php $this->render_icloud_config(); ?>
			<?php $this->render_google_chat_config(); ?>
		</div>

		<style>
			.platform-config {
				margin: 20px 0;
				border: 1px solid #ddd;
				border-radius: 5px;
			}
			.platform-config-header {
				background: #f5f5f5;
				padding: 15px;
				cursor: pointer;
				display: flex;
				align-items: center;
				justify-content: space-between;
			}
			.platform-config-header:hover {
				background: #e8e8e8;
			}
			.platform-config-header h3 {
				margin: 0;
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.platform-config-content {
				display: none;
				padding: 20px;
				border-top: 1px solid #ddd;
			}
			.platform-config.active .platform-config-content {
				display: block;
			}
			.platform-config.active .platform-config-header::after {
				content: "▼";
			}
			.platform-config-header::after {
				content: "▶";
				font-size: 12px;
			}
			.code-snippet {
				background: #f5f5f5;
				padding: 10px;
				border-left: 3px solid #2271b1;
				font-family: monospace;
				white-space: pre-wrap;
			}
		</style>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			document.querySelectorAll('.platform-config-header').forEach(function(header) {
				header.addEventListener('click', function() {
					this.parentElement.classList.toggle('active');
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render Telegram configuration section
	 */
	protected function render_telegram_config() {
		$mini_app_url = class_exists( 'WP_MCP_AI_Telegram_Mini_App_Controller' )
			? WP_MCP_AI_Telegram_Mini_App_Controller::get_mini_app_url()
			: rest_url( 'mcp-ai/v1/telegram-mini-app' );

		// Gather per-connection Mini App URLs for multi-bot setups.
		$per_connection_urls = array();
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			if ( is_array( $all_connections ) ) {
				foreach ( $all_connections as $conn_id => $conn ) {
					if (
						isset( $conn['connection_type'] )
						&& 'telegram' === $conn['connection_type']
						&& ! empty( $conn['enabled'] )
					) {
						$label                 = ! empty( $conn['name'] ) ? $conn['name'] : $conn_id;
						$per_connection_urls[] = array(
							'id'    => sanitize_key( $conn_id ),
							'label' => $label,
							'url'   => class_exists( 'WP_MCP_AI_Telegram_Mini_App_Controller' )
								? WP_MCP_AI_Telegram_Mini_App_Controller::get_mini_app_url( sanitize_key( $conn_id ) )
								: rest_url( 'mcp-ai/v1/telegram-mini-app/' . sanitize_key( $conn_id ) ),
						);
					}
				}
			}
		}
		?>
		<div class="platform-config">
			<div class="platform-config-header">
				<h3>📱 <?php esc_html_e( 'Telegram Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			</div>
			<div class="platform-config-content">
				<p><?php esc_html_e( 'Telegram Bot API provides comprehensive messaging capabilities with rich media support.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li><?php esc_html_e( 'Open Telegram and search for @BotFather', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Send /newbot command and follow the prompts', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Copy the bot token provided by BotFather', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Paste the token in the field below', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Bot Token', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" name="telegram_bot_token" class="regular-text" placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz" />
							<p class="description"><?php esc_html_e( 'Your Telegram bot token from @BotFather', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/telegram' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Configure this URL in your Telegram bot settings', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mini App URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" readonly="readonly" value="<?php echo esc_url( $mini_app_url ); ?>" class="large-text code" onclick="this.select();" onfocus="this.select();" style="background-color:#f0f0f0;" />
							<p class="description">
								<?php
								printf(
									/* translators: 1: opening <a> tag, 2: closing </a> tag */
									esc_html__( 'Provide this URL to @BotFather when configuring your bot\'s %1$sMini App (Web App)%2$s. In BotFather, use /newapp or /setmenubutton and paste this URL to enable the "Open App" button for your users.', 'mcp-ai-wpoos-pro' ),
									'<a href="https://core.telegram.org/bots/webapps" target="_blank" rel="noopener noreferrer">',
									'</a>'
								);
								?>
							</p>
							<?php if ( count( $per_connection_urls ) > 1 ) : ?>
								<div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 2px;">
									<strong><?php esc_html_e( 'Per-Bot Mini App URLs (multi-bot):', 'mcp-ai-wpoos-pro' ); ?></strong>
									<p class="description" style="margin-top: 4px;">
										<?php esc_html_e( 'When running multiple Telegram bots, each bot must use its own unique URL below so that initData validation uses the correct bot token.', 'mcp-ai-wpoos-pro' ); ?>
									</p>
									<?php foreach ( $per_connection_urls as $_pcu ) : ?>
										<div style="margin-top: 6px;">
											<label style="font-weight: 600; display: block; margin-bottom: 2px;"><?php echo esc_html( $_pcu['label'] ); ?></label>
											<input type="text" readonly="readonly" value="<?php echo esc_url( $_pcu['url'] ); ?>" class="large-text code" onclick="this.select();" onfocus="this.select();" style="background-color:#f0f0f0;" />
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mini App Template', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php
							$_active_tpl = get_option( 'wp_mcp_ai_telegram_mini_app_template', 'default' );

							if ( ! class_exists( 'WP_MCP_AI_Telegram_Mini_App_Template_Registry' ) ) {
								$_tpl_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-mini-app-templates.php';
								if ( file_exists( $_tpl_file ) ) {
									require_once $_tpl_file;
								}
							}

							$_all_templates = class_exists( 'WP_MCP_AI_Telegram_Mini_App_Template_Registry' )
								? WP_MCP_AI_Telegram_Mini_App_Template_Registry::get_all_meta()
								: array();

							$_active_name = __( 'Content Manager (Default)', 'mcp-ai-wpoos-pro' );
							foreach ( $_all_templates as $_tpl ) {
								if ( $_tpl['slug'] === $_active_tpl ) {
									$_active_name = $_tpl['icon'] . ' ' . $_tpl['name'];
									break;
								}
							}
							?>
							<p style="margin: 0 0 8px 0;">
								<?php esc_html_e( 'Active global template:', 'mcp-ai-wpoos-pro' ); ?>
								<strong><?php echo esc_html( $_active_name ); ?></strong>
							</p>
							<a href="<?php echo esc_url( add_query_arg( 'tab', 'mini_app_builder', admin_url( 'admin.php?page=' . $this->page_slug ) ) ); ?>" class="button button-secondary">
								📱 <?php esc_html_e( 'Open Mini App Builder →', 'mcp-ai-wpoos-pro' ); ?>
							</a>
							<p class="description" style="margin-top: 6px;">
								<?php esc_html_e( 'Use the Mini App Builder tab to choose from 6 pre-built templates with live preview. Individual bot connections can override the global default in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Group & Community Chat', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'To allow your bot to respond in Telegram groups and supergroups, enable the "Enable Group Chats" option on the Telegram connection in the Remote Site Manager. By default the bot responds to every message in the group. Optionally enable "Require Mention" so the bot only replies when @mentioned or when a message is a reply to the bot.', 'mcp-ai-wpoos-pro' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Go to the Remote Site Manager and edit your Telegram connection', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Check "Enable Group Chats" to allow the bot to respond in groups', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Optionally check "Require Mention" so the bot only replies when @mentioned', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Enter the Bot Username (e.g. @mybot) so mention detection works correctly', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Add the bot to your Telegram group and send it a message', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<div class="notice notice-info inline" style="margin: 12px 0;">
					<p><strong><?php esc_html_e( 'Important: Telegram Bot Privacy Mode', 'mcp-ai-wpoos-pro' ); ?></strong></p>
					<p><?php esc_html_e( 'By default Telegram bots run in Privacy Mode, which means the bot only receives messages that directly @mention it, replies to its messages, or commands (messages starting with /). To allow the bot to receive and respond to ALL messages in a group:', 'mcp-ai-wpoos-pro' ); ?></p>
					<ol>
						<li><?php esc_html_e( 'Open Telegram and message @BotFather', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Send /setprivacy', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Select your bot', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Choose "Disable" to turn off Privacy Mode', 'mcp-ai-wpoos-pro' ); ?></li>
					</ol>
					<p><?php esc_html_e( 'After disabling Privacy Mode, remove and re-add the bot to existing groups for the change to take effect.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>

				<h4><?php esc_html_e( 'Test Group Link', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'Paste a Telegram group invite link or @username below to quickly open the group and verify your bot is active. To send a test message from your bot, use the "Test Send to Group/Channel" feature on the connection edit page in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Group Link', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<div style="display: flex; gap: 8px; align-items: flex-start;">
								<input type="text" id="wp-mcp-ai-tg-group-link" class="regular-text" placeholder="<?php esc_attr_e( 'https://t.me/+abc123 or https://t.me/groupname or @groupname', 'mcp-ai-wpoos-pro' ); ?>" style="flex: 1;" />
								<button type="button" id="wp-mcp-ai-tg-open-group-btn" class="button button-secondary">
									<?php esc_html_e( 'Open Group', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</div>
							<div id="wp-mcp-ai-tg-group-link-result" style="display: none; margin-top: 8px;"></div>
							<p class="description">
								<?php esc_html_e( 'Enter a Telegram group invite link (e.g. https://t.me/+abc123 or https://t.me/groupname) or an @username. Clicking "Open Group" will open the link in a new tab so you can verify the bot is a member and receiving messages.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<p class="description" style="margin-top: 4px;">
								<strong><?php esc_html_e( 'Tip:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php esc_html_e( 'For private groups (links with +), you need the numeric chat ID (e.g. -1001234567890) to send test messages via the API. Open the group, send /start to your bot, then check the plugin logs for the chat ID.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<script>
				(function() {
					var openBtn   = document.getElementById('wp-mcp-ai-tg-open-group-btn');
					var linkInput = document.getElementById('wp-mcp-ai-tg-group-link');
					var resultDiv = document.getElementById('wp-mcp-ai-tg-group-link-result');
					if (!openBtn || !linkInput) { return; }

					openBtn.addEventListener('click', function() {
						var val = linkInput.value.trim();
						if (!val) {
							if (resultDiv) {
								resultDiv.style.display = 'block';
								resultDiv.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p><?php echo esc_js( __( 'Please enter a Telegram group link or @username.', 'mcp-ai-wpoos-pro' ) ); ?></p></div>';
							}
							return;
						}

						var url = '';

						// Already a full URL.
						if (/^https?:\/\//i.test(val)) {
							url = val;
						}
						// @username format.
						else if (/^@/.test(val)) {
							url = 'https://t.me/' + val.replace(/^@/, '');
						}
						// Bare username (letters, digits, underscores, 5+ chars).
						else if (/^[a-zA-Z][a-zA-Z0-9_]{4,}$/.test(val)) {
							url = 'https://t.me/' + val;
						}
						// Numeric chat ID — cannot open directly; show guidance.
						else if (/^-?\d+$/.test(val)) {
							if (resultDiv) {
								resultDiv.style.display = 'block';
								resultDiv.innerHTML = '<div class="notice notice-info inline" style="margin:0;"><p><?php echo esc_js( __( 'Numeric chat IDs cannot be opened as a link. Use the "Test Send to Group/Channel" feature on the connection edit page to send a test message to this chat ID.', 'mcp-ai-wpoos-pro' ) ); ?></p></div>';
							}
							return;
						}
						else {
							url = 'https://t.me/' + val;
						}

						// Validate URL scheme — only allow https (and http for local dev).
						if (!/^https?:\/\//i.test(url)) {
							if (resultDiv) {
								resultDiv.style.display = 'block';
								resultDiv.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p><?php echo esc_js( __( 'Invalid URL. Only http and https links are supported.', 'mcp-ai-wpoos-pro' ) ); ?></p></div>';
							}
							return;
						}

						if (resultDiv) {
							resultDiv.style.display = 'block';
							resultDiv.innerHTML = '<div class="notice notice-success inline" style="margin:0;"><p><?php echo esc_js( __( 'Opening group link in a new tab…', 'mcp-ai-wpoos-pro' ) ); ?> <a href="' + url.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener noreferrer">' + url.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</a></p></div>';
						}
						window.open(url, '_blank', 'noopener,noreferrer');
					});
				})();
				</script>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://core.telegram.org/bots" target="_blank"><?php esc_html_e( 'Telegram Bot API Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://core.telegram.org/bots/api" target="_blank"><?php esc_html_e( 'API Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://core.telegram.org/bots/webapps" target="_blank"><?php esc_html_e( 'Mini Apps (Web Apps) Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render WhatsApp configuration section
	 */
	protected function render_whatsapp_config() {
		$nonce = wp_create_nonce( 'wp_mcp_ai_fetch_whatsapp_phone_numbers' );
		?>
		<div class="platform-config">
			<div class="platform-config-header">
				<h3>💬 <?php esc_html_e( 'WhatsApp Business Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			</div>
			<div class="platform-config-content">
				<p><?php esc_html_e( 'WhatsApp Business API enables enterprise-grade messaging with template messages and rich media.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li><?php esc_html_e( 'Sign up for WhatsApp Business API through an approved provider', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Complete business verification process', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Enter your WhatsApp Business Account ID and System User Access Token below', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Click "Retrieve Phone Numbers" to automatically fetch your Phone Number ID', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Configure webhook for receiving messages', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Business Account ID', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" id="whatsapp_business_account_id" name="whatsapp_business_account_id" class="regular-text" placeholder="123456789012345" />
							<p class="description"><?php esc_html_e( 'Your WhatsApp Business Account (WABA) ID from Meta Business Manager', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Access Token', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" id="whatsapp_access_token" name="whatsapp_access_token" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Your system user access token from Meta Business Manager', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Phone Number ID', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
								<input type="text" id="whatsapp_phone_number_id" name="whatsapp_phone_number_id" class="regular-text" />
								<button type="button" id="wp-mcp-ai-fetch-whatsapp-phones" class="button button-secondary">
									<?php esc_html_e( 'Retrieve Phone Numbers', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</div>
							<div id="wp-mcp-ai-whatsapp-phone-result" style="margin-top:8px;"></div>
							<p class="description"><?php esc_html_e( 'Enter your Phone Number ID manually, or click "Retrieve Phone Numbers" to fetch it automatically using the Business Account ID and Access Token above.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/whatsapp' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Configure this in your WhatsApp Business settings', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verify Token', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" name="whatsapp_verify_token" class="regular-text" placeholder="<?php esc_attr_e( 'Generate a secure token', 'mcp-ai-wpoos-pro' ); ?>" />
							<p class="description"><?php esc_html_e( 'Use this token when setting up webhooks', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://developers.facebook.com/docs/whatsapp" target="_blank"><?php esc_html_e( 'WhatsApp Business Platform Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://developers.facebook.com/docs/whatsapp/cloud-api" target="_blank"><?php esc_html_e( 'Cloud API Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
				</ul>
			</div>
		</div>

		<script>
		( function() {
			var wpMcpAiAjax = typeof ajaxurl !== 'undefined' ? ajaxurl : <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var btn = document.getElementById( 'wp-mcp-ai-fetch-whatsapp-phones' );
			if ( ! btn ) {
				return;
			}
			btn.addEventListener( 'click', function() {
				var wabaId      = document.getElementById( 'whatsapp_business_account_id' ).value.trim();
				var accessToken = document.getElementById( 'whatsapp_access_token' ).value.trim();
				var resultDiv   = document.getElementById( 'wp-mcp-ai-whatsapp-phone-result' );

				if ( ! wabaId || ! accessToken ) {
					resultDiv.innerHTML = '<span style="color:#d63638;"><?php echo esc_js( __( 'Please enter both a Business Account ID and an Access Token first.', 'mcp-ai-wpoos-pro' ) ); ?></span>';
					return;
				}

				btn.disabled    = true;
				btn.textContent = '<?php echo esc_js( __( 'Retrieving\u2026', 'mcp-ai-wpoos-pro' ) ); ?>';
				resultDiv.innerHTML = '';

				var data = new FormData();
				data.append( 'action', 'wp_mcp_ai_fetch_whatsapp_phone_numbers' );
				data.append( 'nonce', '<?php echo esc_js( $nonce ); ?>' );
				data.append( 'business_account_id', wabaId );
				data.append( 'access_token', accessToken );

				fetch( wpMcpAiAjax, { method: 'POST', body: data, credentials: 'same-origin' } )
					.then( function( r ) {
						if ( ! r.ok ) {
							throw new Error( r.status );
						}
						return r.json();
					} )
					.then( function( json ) {
						btn.disabled    = false;
						btn.textContent = '<?php echo esc_js( __( 'Retrieve Phone Numbers', 'mcp-ai-wpoos-pro' ) ); ?>';

						if ( ! json.success ) {
							resultDiv.innerHTML = '<span style="color:#d63638;">' + json.data.message + '</span>';
							return;
						}

						var phones = json.data.phone_numbers;
						if ( phones.length === 1 ) {
							document.getElementById( 'whatsapp_phone_number_id' ).value = phones[0].id;
							resultDiv.innerHTML = '<span style="color:#00a32a;"><?php echo esc_js( __( 'Phone number ID set automatically.', 'mcp-ai-wpoos-pro' ) ); ?> ' + phones[0].display_name + ' (' + phones[0].id + ')</span>';
						} else {
							var select = '<select id="wp-mcp-ai-whatsapp-phone-select" style="max-width:350px;">';
							select += '<option value=""><?php echo esc_js( __( '-- Select a phone number --', 'mcp-ai-wpoos-pro' ) ); ?></option>';
							phones.forEach( function( p ) {
								select += '<option value="' + p.id + '">' + p.display_name + ( p.verified_name ? ' \u2013 ' + p.verified_name : '' ) + ' (' + p.id + ')</option>';
							} );
							select += '</select> <button type="button" id="wp-mcp-ai-whatsapp-phone-apply" class="button"><?php echo esc_js( __( 'Use Selected', 'mcp-ai-wpoos-pro' ) ); ?></button>';
							resultDiv.innerHTML = select;

							document.getElementById( 'wp-mcp-ai-whatsapp-phone-apply' ).addEventListener( 'click', function() {
								var sel = document.getElementById( 'wp-mcp-ai-whatsapp-phone-select' );
								if ( sel.value ) {
									document.getElementById( 'whatsapp_phone_number_id' ).value = sel.value;
									resultDiv.innerHTML = '<span style="color:#00a32a;"><?php echo esc_js( __( 'Phone Number ID applied.', 'mcp-ai-wpoos-pro' ) ); ?></span>';
								}
							} );
						}
					} )
					.catch( function( err ) {
						btn.disabled    = false;
						btn.textContent = '<?php echo esc_js( __( 'Retrieve Phone Numbers', 'mcp-ai-wpoos-pro' ) ); ?>';
						resultDiv.innerHTML = '<span style="color:#d63638;"><?php echo esc_js( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?></span>';
					} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Render Slack configuration section
	 */
	protected function render_slack_config() {
		?>
		<div class="platform-config">
			<div class="platform-config-header">
				<h3>💼 <?php esc_html_e( 'Slack Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			</div>
			<div class="platform-config-content">
				<p><?php esc_html_e( 'Slack API enables workspace integration with channels, threads, and interactive components.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li><?php esc_html_e( 'Go to https://api.slack.com/apps and create a new app', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Enable OAuth & Permissions and add required scopes', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Install the app to your workspace', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Copy the Bot User OAuth Token', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Bot Token', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" name="slack_bot_token" class="regular-text" placeholder="xoxb-your-bot-token" />
							<p class="description"><?php esc_html_e( 'Your Slack Bot User OAuth Token', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Signing Secret', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" name="slack_signing_secret" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Used to verify requests from Slack', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/slack' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Configure as Event Subscription URL in Slack app settings', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Required Scopes', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p class="description" style="margin-bottom: 6px;">
					<?php esc_html_e( 'Add these OAuth Bot Token Scopes in your Slack app (api.slack.com/apps â OAuth & Permissions). Reinstall the app to the workspace after any scope changes.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'OAuth Scope', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Required For', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr><td><code>chat:write</code></td><td><?php esc_html_e( 'Sending AI replies to channels and DMs', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>app_mentions:read</code></td><td><?php esc_html_e( '@mention detection â enables app_mention events so the bot replies when @mentioned in any channel', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>channels:history</code></td><td><?php esc_html_e( 'Reading messages in public channels (message.channels event)', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>groups:history</code></td><td><?php esc_html_e( 'Reading messages in private channels (message.groups event)', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>im:history</code></td><td><?php esc_html_e( 'Reading direct messages sent to the bot (message.im event)', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>mpim:history</code></td><td><?php esc_html_e( 'Reading messages in multi-person DMs (message.mpim event)', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>channels:read</code></td><td><?php esc_html_e( 'Listing and identifying public channels', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>groups:read</code></td><td><?php esc_html_e( 'Listing and identifying private channels', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>im:read</code></td><td><?php esc_html_e( 'Listing direct message conversations', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						<tr><td><code>users:read</code></td><td><?php esc_html_e( 'Looking up user display names and info', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					</tbody>
				</table>

				<h4><?php esc_html_e( 'Event Subscriptions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'In your Slack app under Event Subscriptions â Subscribe to bot events, add:', 'mcp-ai-wpoos-pro' ); ?></p>
				<div class="code-snippet">app_mention, message.channels, message.groups, message.im, message.mpim</div>
				<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'The app_mention event triggers when a user @mentions the bot; message.* events cover all messages in channels/DMs where the bot is a member.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://api.slack.com/docs" target="_blank"><?php esc_html_e( 'Slack API Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://api.slack.com/methods" target="_blank"><?php esc_html_e( 'API Methods Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://docs.slack.dev/reference/scopes/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Slack Scopes Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Discord configuration section
	 */
	protected function render_discord_config() {
		?>
		<div class="platform-config">
			<div class="platform-config-header">
				<h3>🎮 <?php esc_html_e( 'Discord Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			</div>
			<div class="platform-config-content">
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: link to Discord Developer Portal */
							__( 'Discord integration uses the <a href="%s" target="_blank" rel="noopener noreferrer">Discord Developer Portal</a> to create a bot application that receives messages from servers (guilds) via the Discord Gateway (WebSocket). The bot joins your server with specific permissions and responds to messages in channels and DMs. An Interactions Endpoint URL can also be registered to handle slash commands without maintaining a persistent connection.', 'mcp-ai-wpoos-pro' ),
							'https://discord.com/developers/applications'
						),
						array(
							'a' => array(
								'href'   => true,
								'target' => true,
								'rel'    => true,
							),
						)
					);
					?>
				</p>

				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: link to Discord Developer Portal */
								__( 'Go to the <a href="%s" target="_blank" rel="noopener noreferrer">Discord Developer Portal</a> and click <strong>New Application</strong>. Give it a name and save.', 'mcp-ai-wpoos-pro' ),
								'https://discord.com/developers/applications'
							),
							array(
								'a'      => array(
									'href'   => true,
									'target' => true,
									'rel'    => true,
								),
								'strong' => array(),
							)
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							__( 'In the application, go to the <strong>Bot</strong> tab. Click <strong>Add Bot</strong> (or <strong>Reset Token</strong> to reveal a new token). Copy the <strong>Bot Token</strong> — this is the primary credential for the Discord connection.', 'mcp-ai-wpoos-pro' ),
							array( 'strong' => array() )
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							__( 'On the <strong>Bot</strong> tab, scroll to <strong>Privileged Gateway Intents</strong> and enable <strong>Server Members Intent</strong> and <strong>Message Content Intent</strong>. These are required to read message content and user information.', 'mcp-ai-wpoos-pro' ),
							array( 'strong' => array() )
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							__( 'Go to <strong>OAuth2 → URL Generator</strong>. Under Scopes, select <code>bot</code> and <code>applications.commands</code>. Under Bot Permissions, select the required permissions below. Copy the generated URL and open it in a browser to invite the bot to your server.', 'mcp-ai-wpoos-pro' ),
							array(
								'strong' => array(),
								'code'   => array(),
							)
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							__( 'Copy the <strong>Application ID</strong> and <strong>Public Key</strong> from the <strong>General Information</strong> tab. Set the Interactions Endpoint URL to the Webhook URL shown below.', 'mcp-ai-wpoos-pro' ),
							array( 'strong' => array() )
						);
						?>
					</li>
					<li><?php esc_html_e( 'Add a Discord connection in the Remote Site Manager with the Bot Token, Application ID, and Public Key. Assign an AI Assistant and save.', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<h4><?php esc_html_e( 'Connection Credentials (set in Remote Site Manager)', 'mcp-ai-wpoos-pro' ); ?></h4>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Bot Token', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Your Discord bot token from the Bot tab of the Developer Portal. This is the primary credential used to authenticate all Discord API requests. Set per-connection in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Application ID', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Your Discord application ID from the General Information tab. Required for registering slash commands and building invite URLs. Set per-connection in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Public Key', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Your Discord application public key from the General Information tab. Used to verify the Ed25519 signature on interaction requests sent to the Interactions Endpoint URL. Set per-connection in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL (Interactions Endpoint)', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/discord' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Set this as the Interactions Endpoint URL in the Discord Developer Portal → General Information. Discord will send all interaction payloads (slash commands, buttons, etc.) to this URL, verified using the Ed25519 Public Key.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Required Privileged Gateway Intents', 'mcp-ai-wpoos-pro' ); ?></h4>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Intent', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Required For', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>Server Members Intent</code></td>
							<td><?php esc_html_e( 'Receiving member join/leave events and looking up user information by ID', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
						<tr>
							<td><code>Message Content Intent</code></td>
							<td><?php esc_html_e( 'Reading the content of messages in channels — required for AI reply processing. Without this, message content is always empty.', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
					</tbody>
				</table>

				<h4><?php esc_html_e( 'Required Bot Permissions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="code-snippet">Send Messages, Read Messages/View Channels, Read Message History, Embed Links, Attach Files, Use Application Commands</div>
				<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'Add Manage Channels and Manage Roles only if the AI assistant needs channel/role management capabilities.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><?php esc_html_e( 'Test Connection & Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><strong><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Verifies the Bot Token by querying the Discord API. Available on the connection edit page in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Simulates an incoming Discord message and generates an AI-powered reply. Requires at least one assigned assistant.', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://discord.com/developers/docs/intro" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Discord Developer Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://discord.com/developers/docs/topics/gateway" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Discord Gateway (WebSocket) Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://discord.com/developers/docs/interactions/overview" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Discord Interactions (Slash Commands & Buttons)', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://discord.com/developers/docs/resources/channel" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Channel Resource Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://discord.com/developers/docs/topics/permissions" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Discord Permissions Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Microsoft Teams configuration section
	 */
	protected function render_teams_config() {
		?>
		<div class="platform-config">
			<div class="platform-config-header">
				<h3>👔 <?php esc_html_e( 'Microsoft Teams Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			</div>
			<div class="platform-config-content">
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: link to Microsoft Teams outgoing webhooks documentation */
							__( 'Microsoft Teams integration uses <a href="%s" target="_blank" rel="noopener noreferrer">Outgoing Webhooks</a> to receive messages from Teams channels and DMs. When a user mentions your bot or sends a direct message, Teams calls your Webhook URL signed with an HMAC-SHA256 Security Token. Optional Microsoft Graph API credentials enable AI-generated replies to be posted back into Teams threads, group chats, and personal chats.', 'mcp-ai-wpoos-pro' ),
							'https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-outgoing-webhook'
						),
						array(
							'a' => array(
								'href'   => true,
								'target' => true,
								'rel'    => true,
							),
						)
					);
					?>
				</p>

				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li>
						<?php
						echo wp_kses(
							__( 'Go to <strong>NV oOS Pro → Remote Sites</strong> and click <strong>Add New Connection</strong>. Choose <strong>Microsoft Teams</strong> as the connection type and save to generate your connection-specific Webhook URL.', 'mcp-ai-wpoos-pro' ),
							array( 'strong' => array() )
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: link to Microsoft Teams Admin Center */
								__( 'In Microsoft Teams, open the team where you want the bot. Click <strong>… More options</strong> → <strong>Manage team</strong> → <strong>Apps</strong> tab → <strong>Create outgoing webhook</strong>. Alternatively, visit the <a href="%s" target="_blank" rel="noopener noreferrer">Teams Admin Center</a> → Apps → Manage apps → Outgoing webhooks.', 'mcp-ai-wpoos-pro' ),
								'https://admin.teams.microsoft.com/'
							),
							array(
								'strong' => array(),
								'a'      => array(
									'href'   => true,
									'target' => true,
									'rel'    => true,
								),
							)
						);
						?>
					</li>
					<li><?php esc_html_e( 'Fill in a Name (e.g. "AI Assistant"), Callback URL (paste the Webhook URL from step 1), and an optional Description. Click Create.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Copy the Security Token that Teams displays after creation. Paste it into the Security Token field in the Remote Site Manager for this connection.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li>
						<?php
						echo wp_kses(
							__( '<strong>Optional — AI replies via Graph API:</strong> To enable the bot to post AI-generated replies back into Teams, obtain a Microsoft Graph API Bearer token (e.g. via Azure AD application credentials with <code>ChannelMessage.Send</code> and <code>Chat.ReadWrite</code> permissions) and save it in the Graph Access Token field.', 'mcp-ai-wpoos-pro' ),
							array(
								'strong' => array(),
								'code'   => array(),
							)
						);
						?>
					</li>
					<li><?php esc_html_e( 'Assign an AI Assistant to the connection in the Remote Site Manager, then @mention your outgoing webhook by name in any Teams channel to trigger an AI reply.', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<h4><?php esc_html_e( 'Connection Credentials (set in Remote Site Manager)', 'mcp-ai-wpoos-pro' ); ?></h4>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/teams' ) ); ?></code>
							<p class="description">
								<?php esc_html_e( 'Generic URL for single-tenant setups. Each connection in the Remote Site Manager also gets a dedicated per-connection URL (e.g. /webhooks/teams/{id}) — use that URL as the Teams Outgoing Webhook Callback URL for multi-tenant configurations.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Security Token (Signing Secret)', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p class="description">
								<?php esc_html_e( 'The HMAC-SHA256 Security Token shown by Teams when you create the Outgoing Webhook. This is the required credential — it is set per-connection in the Remote Site Manager (Security Token field). Every incoming Teams request is validated against this secret before processing.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Microsoft Graph Access Token (Optional)', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p class="description">
								<?php esc_html_e( 'Optional Bearer token used to send AI-generated replies back into Teams channels and chats via the Microsoft Graph API. Obtain this token from your Azure AD application using client credentials or admin consent. Set it per-connection in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tenant ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p class="description">
								<?php esc_html_e( 'Optional Azure AD tenant ID for reference. Set per-connection in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'App ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p class="description">
								<?php esc_html_e( 'Optional Azure AD application ID for reference or use with the Declarative Agent Manifest. Set per-connection in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Security', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'Every incoming Teams Outgoing Webhook request is validated using HMAC-SHA256 with your Security Token. The request body is hashed with the base64-decoded Security Token and the result (base64-encoded) is compared against the Authorization header sent by Teams. Requests with a timestamp older than 5 minutes are rejected to prevent replay attacks.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><?php esc_html_e( 'Conversation Types', 'mcp-ai-wpoos-pro' ); ?></h4>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Behavior', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>channel</code></td>
							<td><?php esc_html_e( 'Messages in team channels. Replies are posted in-thread via the Graph API replies endpoint to keep conversations tidy. The "Require Mention" option applies here.', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
						<tr>
							<td><code>groupChat</code></td>
							<td><?php esc_html_e( 'Messages in Teams group chats. Replies use the Graph API /chats/{id}/messages endpoint. DM bypass: require_mention is not enforced for group chats.', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
						<tr>
							<td><code>personal</code></td>
							<td><?php esc_html_e( 'Direct messages (DMs) to the bot. Always replied to regardless of the "Require Mention" setting, matching industry-standard DM bot behavior.', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
					</tbody>
				</table>

				<h4><?php esc_html_e( 'Required Graph API Permissions (for AI replies)', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="code-snippet">ChannelMessage.Send, Chat.ReadWrite, Channel.ReadBasic.All, Team.ReadBasic.All</div>
				<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'These permissions are only needed if you configure a Microsoft Graph Access Token to enable proactive AI replies. No Graph permissions are required to receive incoming messages via the Outgoing Webhook alone.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><?php esc_html_e( 'Declarative Agent Manifest', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: opening <a> tag, 2: closing </a> tag */
							__( 'After saving a Teams connection in the Remote Site Manager, use the <strong>Generate Manifest</strong> button on the connection edit page to create a %1$sMicrosoft 365 Copilot declarative agent manifest%2$s. Download the JSON file and upload it to the Microsoft Teams Developer Portal to publish your AI assistant as a Teams app.', 'mcp-ai-wpoos-pro' ),
							'<a href="https://learn.microsoft.com/en-us/microsoft-365-copilot/extensibility/overview-declarative-agent" target="_blank" rel="noopener noreferrer">',
							'</a>'
						),
						array(
							'strong' => array(),
							'a'      => array(
								'href'   => true,
								'target' => true,
								'rel'    => true,
							),
						)
					);
					?>
				</p>

				<h4><?php esc_html_e( 'Test Connection & Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'After adding a Teams connection in the Remote Site Manager, use the following features on the connection edit page:', 'mcp-ai-wpoos-pro' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Test Graph Token', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Verifies your Microsoft Graph Access Token by querying the Graph API. Requires a valid Graph token to be saved.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Simulates an incoming Teams message and generates an AI-powered reply using the first assigned assistant.', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-outgoing-webhook" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create an Outgoing Webhook in Microsoft Teams', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://learn.microsoft.com/en-us/microsoftteams/platform/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Microsoft Teams Developer Platform', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://learn.microsoft.com/en-us/graph/api/resources/teams-api-overview" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Microsoft Graph Teams API', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://learn.microsoft.com/en-us/graph/api/channel-post-messages" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Send a Message to a Teams Channel (Graph API)', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://learn.microsoft.com/en-us/microsoft-365-copilot/extensibility/overview-declarative-agent" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Microsoft 365 Copilot Declarative Agents', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://admin.teams.microsoft.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Microsoft Teams Admin Center', 'mcp-ai-wpoos-pro' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Office 365 configuration section
	 */
	protected function render_office365_config() {
		?>
		<div class="platform-config">
			<div class="platform-config-header">
				<h3>📧 <?php esc_html_e( 'Office 365 Configuration (Outlook, OneDrive)', 'mcp-ai-wpoos-pro' ); ?></h3>
			</div>
			<div class="platform-config-content">
				<p><?php esc_html_e( 'Office 365 integration provides Outlook mail, OneDrive file management, and Office document access via the Microsoft Graph API. Uses the same Azure AD application as Microsoft Teams.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li><?php esc_html_e( 'Use the same Azure AD application configured for Microsoft Teams (or register a new one in the Azure Portal)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Add the required Microsoft Graph API permissions listed below', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Grant admin consent for the added permissions', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Configure the Outlook webhook URL below to receive new mail notifications', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Click "Test Connection" on the connection edit page to verify your credentials with Microsoft Graph', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Assign AI Assistants and use "Test Auto-Reply" to simulate incoming email responses', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Application (Client) ID', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" name="office365_client_id" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Your Azure AD application ID (can be the same as Microsoft Teams)', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Client Secret', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" name="office365_client_secret" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Your Azure AD client secret', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tenant ID', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" name="office365_tenant_id" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Your Azure AD tenant ID', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Outlook Webhook URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/outlook' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Use this URL as the notificationUrl when creating a Microsoft Graph subscription for mail changes.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Client State (Webhook Secret)', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" name="office365_client_state" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Optional shared secret sent with change notifications for signature validation. Set this value when creating the Graph subscription.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Required API Permissions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="code-snippet">Mail.Read, Mail.Send, Files.Read.All, Files.ReadWrite.All, User.Read</div>

				<h4><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><strong><?php esc_html_e( 'Send Outlook Mail', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Send emails via Microsoft Outlook', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Get Outlook Messages', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Retrieve inbox or folder messages', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'List OneDrive Files', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Browse files and folders in OneDrive', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Get OneDrive File', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Retrieve file metadata and download URL', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Upload OneDrive File', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Upload files to OneDrive', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Test Connection & Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'After adding an Office 365 connection in the Remote Site Manager, use the following features on the connection edit page:', 'mcp-ai-wpoos-pro' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Verifies your Azure AD credentials by requesting an access token from the Microsoft identity platform and querying the Graph API.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Simulates an incoming Outlook email and generates an AI-powered reply using the first assigned assistant. Optionally sends the reply to a specified email address.', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://learn.microsoft.com/en-us/graph/api/resources/mail-api-overview" target="_blank"><?php esc_html_e( 'Microsoft Graph Mail API', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://learn.microsoft.com/en-us/graph/api/resources/onedrive" target="_blank"><?php esc_html_e( 'Microsoft Graph OneDrive API', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://learn.microsoft.com/en-us/graph/webhooks" target="_blank"><?php esc_html_e( 'Microsoft Graph Webhooks (Change Notifications)', 'mcp-ai-wpoos-pro' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render iCloud configuration section
	 */
	protected function render_icloud_config() {
		?>
		<div class="platform-config">
			<div class="platform-config-header">
				<h3>☁️ <?php esc_html_e( 'iCloud Drive Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			</div>
			<div class="platform-config-content">
				<p><?php esc_html_e( 'iCloud Drive integration enables file management via a configured gateway service. Since Apple does not provide a direct third-party REST API for iCloud, this channel communicates through a gateway that bridges to Apple CloudKit or iCloud services.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li><?php esc_html_e( 'Set up an iCloud gateway service that provides REST API access to iCloud Drive (e.g. via Apple CloudKit JS or a server-side CloudKit integration)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Obtain the gateway API URL and authentication credentials', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Configure the signing secret for webhook signature verification', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Set up the webhook URL in your gateway to receive file change notifications', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Click "Test Connection" on the connection edit page to verify gateway connectivity', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Assign AI Assistants and use "Test Auto-Reply" to simulate incoming file event responses', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Gateway API URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="url" name="icloud_gateway_url" class="regular-text" placeholder="https://your-gateway.example.com/api/icloud" />
							<p class="description"><?php esc_html_e( 'Base URL of your iCloud gateway REST API (must be HTTPS)', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'API Key', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" name="icloud_api_key" class="regular-text" />
							<p class="description"><?php esc_html_e( 'API key or bearer token for authenticating with your iCloud gateway', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook Signing Secret', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" name="icloud_signing_secret" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Shared HMAC-SHA256 signing secret for validating incoming webhook payloads from the gateway', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/icloud' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Configure this URL in your iCloud gateway to receive file change notifications', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><strong><?php esc_html_e( 'List iCloud Drive Files', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Browse files and folders in iCloud Drive', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Get iCloud Drive File', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Retrieve file metadata and download information', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Upload iCloud Drive File', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Upload files to iCloud Drive', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Supported Webhook Events', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><code>file_created</code> — <?php esc_html_e( 'New file uploaded', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><code>file_modified</code> — <?php esc_html_e( 'Existing file changed', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><code>file_deleted</code> — <?php esc_html_e( 'File removed', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><code>file_shared</code> — <?php esc_html_e( 'File sharing event', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Test Connection & Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'After adding an iCloud Drive connection in the Remote Site Manager, use the following features on the connection edit page:', 'mcp-ai-wpoos-pro' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Verifies your gateway credentials by sending a request to the iCloud gateway API URL and confirming connectivity.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Simulates an incoming file event and generates an AI-powered reply using the first assigned assistant.', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://developer.apple.com/documentation/cloudkit" target="_blank"><?php esc_html_e( 'Apple CloudKit Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://developer.apple.com/icloud/" target="_blank"><?php esc_html_e( 'Apple iCloud Developer Resources', 'mcp-ai-wpoos-pro' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Facebook Messenger configuration section
	 */
	protected function render_messenger_config() {
		$nonce_generate = wp_create_nonce( 'wp_mcp_ai_generate_messenger_token' );
		$nonce_test     = wp_create_nonce( 'wp_mcp_ai_test_messenger_live' );
		$msng_versions  = array( 'v22.0', 'v21.0', 'v20.0', 'v19.0', 'v18.0' );
		?>
		<div class="platform-config">
			<div class="platform-config-header">
				<h3>📘 <?php esc_html_e( 'Facebook Messenger Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			</div>
			<div class="platform-config-content">
				<p><?php esc_html_e( 'Facebook Messenger integration enables page messaging with quick replies, persistent menus, and AI-powered automated responses.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li><?php esc_html_e( 'Go to Meta Developer Dashboard (developers.facebook.com) and create a new app of type "Business"', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Add the Messenger product to your app', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Copy your App ID and App Secret from the App Settings → Basic page', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Enter your App ID and App Secret below, then click "Generate App Access Token" for a server-level token', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'For full Page messaging, obtain a long-lived Page Access Token from Meta Business Suite or Graph API Explorer with the pages_messaging permission', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Enter an optional Page ID to scope the connection to a specific Facebook Page', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Click "Test Connection" to verify your credentials with the Meta API', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Create a secure Verify Token (any random string) and save it below', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Configure the Webhook URL and Verify Token in the Meta Developer Dashboard, then subscribe to the required events', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="wp-mcp-ai-messenger-app-id"><?php esc_html_e( 'App ID', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="text" id="wp-mcp-ai-messenger-app-id" name="messenger_app_id" class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Your App ID from the Meta Developer Dashboard. Required to generate an App Access Token.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wp-mcp-ai-messenger-page-access-token"><?php esc_html_e( 'Page Access Token', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
								<input type="password" id="wp-mcp-ai-messenger-page-access-token" name="messenger_page_access_token" class="regular-text" autocomplete="new-password" />
								<button type="button" id="wp-mcp-ai-messenger-token-toggle" class="button button-small" aria-label="<?php esc_attr_e( 'Show access token', 'mcp-ai-wpoos-pro' ); ?>"><?php esc_html_e( 'Show', 'mcp-ai-wpoos-pro' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( 'Your Facebook Page Access Token. Use "Generate App Access Token" below, or obtain a long-lived Page Access Token from Meta Business Suite or Graph API Explorer.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wp-mcp-ai-messenger-app-secret"><?php esc_html_e( 'App Secret', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="password" id="wp-mcp-ai-messenger-app-secret" name="messenger_app_secret" class="regular-text" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Your App Secret from the Meta Developer Dashboard. Required for webhook signature validation (X-Hub-Signature-256).', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Generate App Access Token', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<button type="button" id="wp-mcp-ai-messenger-generate-token-btn" class="button button-secondary">
								<?php esc_html_e( 'Generate App Access Token', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="wp-mcp-ai-messenger-token-status" style="margin-left:10px; display:none;"></span>
							<p class="description"><?php esc_html_e( 'Enter your App ID and App Secret above, then click to generate an App Access Token. For full Page messaging, obtain a long-lived Page Access Token from Meta Business Suite.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wp-mcp-ai-messenger-page-id"><?php esc_html_e( 'Page ID', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="text" id="wp-mcp-ai-messenger-page-id" name="messenger_page_id" class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Optional: Your Facebook Page ID. Used to scope the connection to a specific page and verify credentials during test.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<button type="button" id="wp-mcp-ai-messenger-test-btn" class="button button-secondary">
								<?php esc_html_e( 'Test Messenger Connection', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="wp-mcp-ai-messenger-test-spinner" class="spinner" style="float:none; vertical-align:middle; display:none;"></span>
							<div id="wp-mcp-ai-messenger-test-result" style="display:none; margin-top:8px;"></div>
							<p class="description"><?php esc_html_e( 'Enter your Page Access Token above, then click to verify credentials with the Meta API.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wp-mcp-ai-messenger-graph-api-version"><?php esc_html_e( 'Graph API Version', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<select id="wp-mcp-ai-messenger-graph-api-version" name="messenger_graph_api_version" class="regular-text">
								<?php foreach ( $msng_versions as $ver ) : ?>
									<option value="<?php echo esc_attr( $ver ); ?>"><?php echo esc_html( $ver ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Meta Graph API version for Messenger API requests. Select the latest version supported by your Meta app.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wp-mcp-ai-messenger-verify-token"><?php esc_html_e( 'Verify Token', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="text" id="wp-mcp-ai-messenger-verify-token" name="messenger_verify_token" class="regular-text" placeholder="<?php esc_attr_e( 'Generate a secure token', 'mcp-ai-wpoos-pro' ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Use this token when setting up webhook subscription in the Meta Developer Dashboard.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/messenger' ) ); ?>" class="large-text code" onclick="this.select();" onfocus="this.select();" style="background-color:#f0f0f0;" />
							<p class="description"><?php esc_html_e( 'Configure this as the Callback URL in the Meta Developer Dashboard webhook settings. Click the field to select the URL.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Required Webhook Subscriptions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="code-snippet">messages, messaging_postbacks, messaging_optins, message_deliveries, message_reads, messaging_referrals, message_reactions</div>
				<p style="margin-top:8px; font-size:13px;">
					<strong><?php esc_html_e( 'Required permissions:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>pages_messaging</code>
				</p>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://developers.facebook.com/docs/messenger-platform" target="_blank"><?php esc_html_e( 'Messenger Platform Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://developers.facebook.com/docs/messenger-platform/send-messages" target="_blank"><?php esc_html_e( 'Send API Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://developers.facebook.com/docs/messenger-platform/webhooks" target="_blank"><?php esc_html_e( 'Webhook Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://developers.facebook.com/tools/explorer/" target="_blank"><?php esc_html_e( 'Graph API Explorer', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://business.facebook.com/settings/system-users" target="_blank"><?php esc_html_e( 'Meta Business Suite – System Users', 'mcp-ai-wpoos-pro' ); ?></a></li>
				</ul>
			</div>
		</div>

		<script>
		( function() {
			var wpMcpAiAjax = typeof ajaxurl !== 'undefined' ? ajaxurl : <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			// Show/Hide Page Access Token toggle.
			var tokenToggleBtn = document.getElementById( 'wp-mcp-ai-messenger-token-toggle' );
			if ( tokenToggleBtn ) {
				tokenToggleBtn.addEventListener( 'click', function() {
					var tokenInput = document.getElementById( 'wp-mcp-ai-messenger-page-access-token' );
					if ( 'password' === tokenInput.type ) {
						tokenInput.type = 'text';
						tokenToggleBtn.textContent = '<?php echo esc_js( __( 'Hide', 'mcp-ai-wpoos-pro' ) ); ?>';
						tokenToggleBtn.setAttribute( 'aria-label', '<?php echo esc_js( __( 'Hide access token', 'mcp-ai-wpoos-pro' ) ); ?>' );
					} else {
						tokenInput.type = 'password';
						tokenToggleBtn.textContent = '<?php echo esc_js( __( 'Show', 'mcp-ai-wpoos-pro' ) ); ?>';
						tokenToggleBtn.setAttribute( 'aria-label', '<?php echo esc_js( __( 'Show access token', 'mcp-ai-wpoos-pro' ) ); ?>' );
					}
				} );
			}

			// Generate App Access Token button.
			var generateBtn = document.getElementById( 'wp-mcp-ai-messenger-generate-token-btn' );
			if ( generateBtn ) {
				generateBtn.addEventListener( 'click', function() {
					var appId     = document.getElementById( 'wp-mcp-ai-messenger-app-id' ).value.trim();
					var appSecret = document.getElementById( 'wp-mcp-ai-messenger-app-secret' ).value.trim();
					var statusEl  = document.getElementById( 'wp-mcp-ai-messenger-token-status' );

					if ( ! appId ) {
						statusEl.style.display = 'inline';
						statusEl.style.color   = '#d63638';
						statusEl.textContent   = '<?php echo esc_js( __( 'Please enter your App ID first.', 'mcp-ai-wpoos-pro' ) ); ?>';
						return;
					}
					if ( ! appSecret ) {
						statusEl.style.display = 'inline';
						statusEl.style.color   = '#d63638';
						statusEl.textContent   = '<?php echo esc_js( __( 'Please enter your App Secret first.', 'mcp-ai-wpoos-pro' ) ); ?>';
						return;
					}

					generateBtn.disabled  = true;
					statusEl.style.display = 'inline';
					statusEl.style.color   = '#646970';
					statusEl.textContent   = '<?php echo esc_js( __( 'Generating\u2026', 'mcp-ai-wpoos-pro' ) ); ?>';

					var data = new FormData();
					data.append( 'action',     'wp_mcp_ai_generate_messenger_token' );
					data.append( 'nonce',      '<?php echo esc_js( $nonce_generate ); ?>' );
					data.append( 'app_id',     appId );
					data.append( 'app_secret', appSecret );

					fetch( wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data } )
						.then( function( response ) {
							if ( ! response.ok ) { throw new Error( 'HTTP ' + response.status ); }
							return response.json();
						} )
						.then( function( result ) {
							generateBtn.disabled = false;
							if ( result.success ) {
								var tokenInput = document.getElementById( 'wp-mcp-ai-messenger-page-access-token' );
								tokenInput.value = result.data.access_token;
								tokenInput.type  = 'text';
								if ( tokenToggleBtn ) {
									tokenToggleBtn.textContent = '<?php echo esc_js( __( 'Hide', 'mcp-ai-wpoos-pro' ) ); ?>';
									tokenToggleBtn.setAttribute( 'aria-label', '<?php echo esc_js( __( 'Hide access token', 'mcp-ai-wpoos-pro' ) ); ?>' );
								}
								statusEl.style.color = '#00a32a';
								statusEl.textContent = '<?php echo esc_js( __( '✓ App Access Token generated and populated.', 'mcp-ai-wpoos-pro' ) ); ?>';
							} else {
								statusEl.style.color = '#d63638';
								statusEl.textContent = result.data || '<?php echo esc_js( __( 'Failed to generate token.', 'mcp-ai-wpoos-pro' ) ); ?>';
							}
						} )
						.catch( function() {
							generateBtn.disabled = false;
							statusEl.style.color = '#d63638';
							statusEl.textContent = '<?php echo esc_js( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?>';
						} );
				} );
			}

			// Test Connection button.
			var testBtn     = document.getElementById( 'wp-mcp-ai-messenger-test-btn' );
			var testSpinner = document.getElementById( 'wp-mcp-ai-messenger-test-spinner' );
			var testResult  = document.getElementById( 'wp-mcp-ai-messenger-test-result' );
			if ( testBtn ) {
				testBtn.addEventListener( 'click', function() {
					var accessToken = document.getElementById( 'wp-mcp-ai-messenger-page-access-token' ).value.trim();
					var pageId      = document.getElementById( 'wp-mcp-ai-messenger-page-id' ).value.trim();
					var apiVersion  = document.getElementById( 'wp-mcp-ai-messenger-graph-api-version' ).value;

					if ( ! accessToken ) {
						testResult.style.display = 'block';
						testResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p><?php echo esc_js( __( 'Please enter your Page Access Token first.', 'mcp-ai-wpoos-pro' ) ); ?></p></div>';
						return;
					}

					testBtn.disabled = true;
					if ( testSpinner ) { testSpinner.style.display = 'inline-block'; }
					testResult.style.display = 'none';
					testResult.innerHTML     = '';

					var data = new FormData();
					data.append( 'action',       'wp_mcp_ai_test_messenger_live' );
					data.append( 'nonce',        '<?php echo esc_js( $nonce_test ); ?>' );
					data.append( 'access_token', accessToken );
					data.append( 'page_id',      pageId );
					data.append( 'api_version',  apiVersion );

					fetch( wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data } )
						.then( function( response ) {
							if ( ! response.ok ) { throw new Error( 'HTTP ' + response.status ); }
							return response.json();
						} )
						.then( function( result ) {
							testBtn.disabled = false;
							if ( testSpinner ) { testSpinner.style.display = 'none'; }
							testResult.style.display = 'block';
							if ( result.success ) {
								var d = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + '<?php echo esc_js( __( 'Connection successful!', 'mcp-ai-wpoos-pro' ) ); ?>' + '</strong></p>';
								if ( d.page_name ) { html += '<p><?php echo esc_js( __( 'Page Name:', 'mcp-ai-wpoos-pro' ) ); ?> ' + d.page_name + '</p>'; }
								if ( d.page_id )   { html += '<p><?php echo esc_js( __( 'Page ID:', 'mcp-ai-wpoos-pro' ) ); ?> ' + d.page_id + '</p>'; }
								if ( d.category )  { html += '<p><?php echo esc_js( __( 'Category:', 'mcp-ai-wpoos-pro' ) ); ?> ' + d.category + '</p>'; }
								if ( d.token_type ) { html += '<p><?php echo esc_js( __( 'Token Type:', 'mcp-ai-wpoos-pro' ) ); ?> ' + d.token_type + '</p>'; }
								html += '</div>';
								if ( d.warning ) {
									html += '<div class="notice notice-warning inline" style="margin:8px 0 0;"><p>' + d.warning + '</p></div>';
								}
								testResult.innerHTML = html;
							} else {
								testResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + ( result.data || '<?php echo esc_js( __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ) ); ?>' ) + '</p></div>';
							}
						} )
						.catch( function() {
							testBtn.disabled = false;
							if ( testSpinner ) { testSpinner.style.display = 'none'; }
							testResult.style.display = 'block';
							testResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p><?php echo esc_js( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?></p></div>';
						} );
				} );
			}
		} )();
		</script>
		<?php
	}

	/**
	 * Render Twitter/X configuration section.
	 */
	protected function render_twitter_config() {
		?>
		<div class="platform-config">
			<div class="platform-config-header">
				<h3>🐦 <?php esc_html_e( 'Twitter / X Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			</div>
			<div class="platform-config-content">
				<p><?php esc_html_e( 'Twitter/X Account Activity API enables real-time Direct Message handling and account event streaming via webhook.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li><?php esc_html_e( 'Go to developer.twitter.com and create a Project and App.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Under App Settings → User Authentication, enable OAuth 1.0a with Read, Write & Direct Messages permissions.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'From Keys and Tokens, generate API Key, API Secret Key, Access Token, and Access Token Secret.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Apply for Account Activity API access and create a dev environment in the Developer Portal.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Add a Twitter connection in the Remote Site Manager — the channel-specific webhook URL will be generated after saving.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Register the webhook URL using the "Manage Twitter Webhook" AI tool (action: register) or via the Twitter API directly.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Subscribe the user using the "Manage Twitter Webhook" AI tool (action: subscribe) to start receiving account events.', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/twitter' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Register this URL (or the channel-specific URL from the Remote Site Manager) in the Twitter Developer Portal and as the Account Activity API webhook endpoint.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Security', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'Every incoming POST event is validated using HMAC-SHA256 (base64-encoded) with your API Secret Key against the X-Twitter-Webhooks-Signature header. CRC challenge requests (GET with crc_token) are answered automatically using the same key.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://developer.twitter.com/en/docs/twitter-api/direct-messages" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Twitter API v2 Direct Messages', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://developer.twitter.com/en/docs/twitter-api/enterprise/account-activity-api/guides/securing-webhooks" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Securing Account Activity Webhooks', 'mcp-ai-wpoos-pro' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Google Chat configuration section.
	 *
	 * @since 1.0.0
	 */
	protected function render_google_chat_config() {
		?>
		<div class="platform-config">
			<div class="platform-config-header">
				<h3>💬 <?php esc_html_e( 'Google Chat Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			</div>
			<div class="platform-config-content">
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: link to Google Chat API docs */
							__( 'Google Chat integration uses the <a href="%s" target="_blank" rel="noopener noreferrer">Google Chat API</a> (HTTP endpoint connection type) to receive messages from Spaces and DMs. When a user @mentions your bot or sends it a direct message, Google Chat delivers the event as an HTTP POST to your Webhook URL. The bot replies synchronously by returning a JSON response, or asynchronously via the Google Chat REST API using a service account.', 'mcp-ai-wpoos-pro' ),
							'https://developers.google.com/chat/how-tos/apps-overview'
						),
						array(
							'a' => array(
								'href'   => true,
								'target' => true,
								'rel'    => true,
							),
						)
					);
					?>
				</p>

				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: link to Google Cloud Console */
								__( 'Go to the <a href="%s" target="_blank" rel="noopener noreferrer">Google Cloud Console</a> and create or select a project.', 'mcp-ai-wpoos-pro' ),
								'https://console.cloud.google.com/'
							),
							array(
								'a' => array(
									'href'   => true,
									'target' => true,
									'rel'    => true,
								),
							)
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: link to Google Chat API page */
								__( '<a href="%s" target="_blank" rel="noopener noreferrer">Enable the Google Chat API</a> for your project.', 'mcp-ai-wpoos-pro' ),
								'https://console.cloud.google.com/apis/library/chat.googleapis.com'
							),
							array(
								'a' => array(
									'href'   => true,
									'target' => true,
									'rel'    => true,
								),
							)
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							__( 'In the Google Cloud Console, go to <strong>Google Chat API → Configuration</strong>. Set the <strong>App URL</strong> (Connection type: HTTP endpoint) to the Webhook URL shown below.', 'mcp-ai-wpoos-pro' ),
							array( 'strong' => array() )
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							__( 'Fill in the <strong>App name</strong>, <strong>Avatar URL</strong>, and <strong>Description</strong>. Under <strong>Functionality</strong>, enable <strong>Receive 1:1 messages</strong> and <strong>Join spaces and group conversations</strong>.', 'mcp-ai-wpoos-pro' ),
							array( 'strong' => array() )
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							__( 'Set <strong>App visibility</strong> to your domain (or specific users during testing). Save the configuration.', 'mcp-ai-wpoos-pro' ),
							array( 'strong' => array() )
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							__( '<strong>Optional — proactive replies via service account:</strong> To enable the bot to send messages proactively (not just as a direct response to an incoming event), create a <strong>Service Account</strong> in <em>IAM & Admin → Service Accounts</em>, download the JSON key, and grant it the <code>chat.messages.create</code> scope. Add a Google Chat connection in the Remote Site Manager and upload or paste the service account credentials.', 'mcp-ai-wpoos-pro' ),
							array(
								'strong' => array(),
								'em'     => array(),
								'code'   => array(),
							)
						);
						?>
					</li>
					<li><?php esc_html_e( 'Add the bot to a Space by @mentioning it and accepting the invitation, or add it directly via Space settings → Manage members → Add apps.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '@Mention the bot in a Space or send it a direct message — the AI assistant will reply.', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<h4><?php esc_html_e( 'Webhook URL (App URL)', 'mcp-ai-wpoos-pro' ); ?></h4>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL (App URL)', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/google-chat' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Set this as the App URL in Google Cloud Console → Google Chat API → Configuration (Connection type: HTTP endpoint). Google Chat will POST all space messages and DMs to this URL.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'How @mentions and DMs Work', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'When a user types "@YourBot what can you do?" in a Space, Google Chat sends a MESSAGE event to the Webhook URL above. This plugin strips the mention markup, matches the message to a configured connection by Space ID (with a fallback to any enabled Google Chat connection for DMs), and fires an AI reply using the assigned assistant.', 'mcp-ai-wpoos-pro' ); ?></p>
				<p><?php esc_html_e( 'For direct messages (DMs), Google Chat creates a unique Space for each user-bot pair. The plugin automatically handles these via a three-tier fallback: exact Space match → generic connection → any enabled Google Chat connection.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><?php esc_html_e( 'Security', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: link to Google Chat security documentation */
							__( 'Google Chat signs HTTP endpoint requests using a Bearer token in the Authorization header. The token is a Google-signed JWT that can be verified against Google\'s public keys. See the <a href="%s" target="_blank" rel="noopener noreferrer">Google Chat security documentation</a> for verification details.', 'mcp-ai-wpoos-pro' ),
							'https://developers.google.com/chat/how-tos/bots-develop#verifying_requests'
						),
						array(
							'a' => array(
								'href'   => true,
								'target' => true,
								'rel'    => true,
							),
						)
					);
					?>
				</p>

				<h4><?php esc_html_e( 'Test Connection & Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><strong><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Verifies Google Chat API connectivity for the connection. Available on the connection edit page in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Simulates an incoming Google Chat message and generates an AI-powered reply. Requires at least one assigned assistant.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Test Incoming Trigger', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Fires a simulated MESSAGE event through the full webhook processing pipeline to verify end-to-end handling.', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://developers.google.com/chat/how-tos/apps-overview" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Chat Apps Overview', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://developers.google.com/chat/how-tos/bots-develop" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Build a Google Chat App (HTTP Endpoint)', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://developers.google.com/chat/reference/rest/v1/spaces.messages" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Chat Messages REST API', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://developers.google.com/chat/how-tos/bots-develop#verifying_requests" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Verifying Google Chat Requests', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://console.cloud.google.com/apis/library/chat.googleapis.com" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Enable Google Chat API in Cloud Console', 'mcp-ai-wpoos-pro' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Get tools list
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			// Core messaging tools.
			'send_chat_message'          => __( 'Send Chat Message', 'mcp-ai-wpoos-pro' ),
			'receive_chat_message'       => __( 'Receive Chat Message', 'mcp-ai-wpoos-pro' ),
			'send_multiplatform_message' => __( 'Send Multi-Platform Message', 'mcp-ai-wpoos-pro' ),

			// Media tools.
			'send_chat_image'            => __( 'Send Chat Image', 'mcp-ai-wpoos-pro' ),
			'send_chat_video'            => __( 'Send Chat Video', 'mcp-ai-wpoos-pro' ),
			'send_chat_document'         => __( 'Send Chat Document', 'mcp-ai-wpoos-pro' ),

			// Channel/Group management.
			'create_chat_channel'        => __( 'Create Chat Channel', 'mcp-ai-wpoos-pro' ),
			'manage_chat_channel'        => __( 'Manage Chat Channel', 'mcp-ai-wpoos-pro' ),
			'list_chat_channels'         => __( 'List Chat Channels', 'mcp-ai-wpoos-pro' ),
			'add_channel_member'         => __( 'Add Channel Member', 'mcp-ai-wpoos-pro' ),
			'remove_channel_member'      => __( 'Remove Channel Member', 'mcp-ai-wpoos-pro' ),

			// Interactive elements.
			'send_interactive_message'   => __( 'Send Interactive Message', 'mcp-ai-wpoos-pro' ),
			'handle_callback_query'      => __( 'Handle Callback Query', 'mcp-ai-wpoos-pro' ),

			// User management.
			'get_chat_user_info'         => __( 'Get Chat User Info', 'mcp-ai-wpoos-pro' ),
			'manage_user_permissions'    => __( 'Manage User Permissions', 'mcp-ai-wpoos-pro' ),

			// Analytics.
			'get_chat_analytics'         => __( 'Get Chat Analytics', 'mcp-ai-wpoos-pro' ),
			'track_message_delivery'     => __( 'Track Message Delivery', 'mcp-ai-wpoos-pro' ),

			// Webhooks.
			'configure_chat_webhook'     => __( 'Configure Chat Webhook', 'mcp-ai-wpoos-pro' ),
			'process_webhook_event'      => __( 'Process Webhook Event', 'mcp-ai-wpoos-pro' ),

			// Advanced features.
			'moderate_chat_content'      => __( 'Moderate Chat Content', 'mcp-ai-wpoos-pro' ),
			'auto_translate_message'     => __( 'Auto-Translate Message', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Chat_Channels_Settings_Page();
}
