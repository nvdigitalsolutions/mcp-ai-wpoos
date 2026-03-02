<?php
/**
 * Chat Channels Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
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

		$saved_assistant       = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
		$enable_logging        = ! empty( $settings['enable_logging'] );
		$enable_rate_limiting  = isset( $settings['enable_rate_limiting'] ) ? (bool) $settings['enable_rate_limiting'] : true;
		$verify_webhook        = isset( $settings['verify_webhook_signatures'] ) ? (bool) $settings['verify_webhook_signatures'] : true;

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
		$sanitized['default_assistant']        = isset( $input['default_assistant'] ) ? absint( $input['default_assistant'] ) : 0;
		$sanitized['enable_logging']           = ! empty( $input['enable_logging'] );
		$sanitized['enable_rate_limiting']     = ! empty( $input['enable_rate_limiting'] );
		$sanitized['verify_webhook_signatures'] = ! empty( $input['verify_webhook_signatures'] );

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

				fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } )
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
				<div class="code-snippet">chat:write, channels:read, channels:manage, groups:read, groups:write, im:read, im:write, users:read</div>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://api.slack.com/docs" target="_blank"><?php esc_html_e( 'Slack API Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://api.slack.com/methods" target="_blank"><?php esc_html_e( 'API Methods Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
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
				<p><?php esc_html_e( 'Discord API enables server management with channels, roles, and rich embedded messages.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li><?php esc_html_e( 'Go to https://discord.com/developers/applications', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Create a new application and add a bot', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Enable required intents (Server Members, Message Content)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Copy the bot token and invite bot to your server', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Bot Token', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" name="discord_bot_token" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Your Discord bot token', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Application ID', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" name="discord_application_id" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Your Discord application ID', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Public Key', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" name="discord_public_key" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Used for verifying interaction requests', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/discord' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Configure as Interactions Endpoint URL', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Required Permissions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="code-snippet">Send Messages, Read Messages/View Channels, Manage Channels, Manage Roles, Embed Links, Attach Files, Read Message History</div>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://discord.com/developers/docs" target="_blank"><?php esc_html_e( 'Discord Developer Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://discord.com/developers/docs/resources/channel" target="_blank"><?php esc_html_e( 'Channel Resource Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
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
				<p><?php esc_html_e( 'Microsoft Teams integration enables enterprise collaboration with channels, tabs, and adaptive cards.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li><?php esc_html_e( 'Go to Azure Portal and register a new application', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Add Microsoft Graph API permissions', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Create a client secret', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Configure redirect URI for OAuth', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Application (Client) ID', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" name="teams_client_id" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Your Azure AD application ID', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Client Secret', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" name="teams_client_secret" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Your Azure AD client secret', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tenant ID', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" name="teams_tenant_id" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Your Azure AD tenant ID', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/teams' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Configure as Bot Messaging Endpoint', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Required API Permissions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="code-snippet">Chat.ReadWrite, Channel.ReadBasic.All, ChannelMessage.Send, Team.ReadBasic.All</div>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://learn.microsoft.com/en-us/microsoftteams/platform/" target="_blank"><?php esc_html_e( 'Microsoft Teams Developer Platform', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://learn.microsoft.com/en-us/graph/api/resources/teams-api-overview" target="_blank"><?php esc_html_e( 'Microsoft Graph Teams API', 'mcp-ai-wpoos-pro' ); ?></a></li>
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

					fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
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

					fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
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
	 * Get tools list
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			// Core messaging tools.
			'send_chat_message'               => __( 'Send Chat Message', 'mcp-ai-wpoos-pro' ),
			'receive_chat_message'            => __( 'Receive Chat Message', 'mcp-ai-wpoos-pro' ),
			'send_multiplatform_message'      => __( 'Send Multi-Platform Message', 'mcp-ai-wpoos-pro' ),

			// Media tools.
			'send_chat_image'                 => __( 'Send Chat Image', 'mcp-ai-wpoos-pro' ),
			'send_chat_video'                 => __( 'Send Chat Video', 'mcp-ai-wpoos-pro' ),
			'send_chat_document'              => __( 'Send Chat Document', 'mcp-ai-wpoos-pro' ),

			// Channel/Group management.
			'create_chat_channel'             => __( 'Create Chat Channel', 'mcp-ai-wpoos-pro' ),
			'manage_chat_channel'             => __( 'Manage Chat Channel', 'mcp-ai-wpoos-pro' ),
			'list_chat_channels'              => __( 'List Chat Channels', 'mcp-ai-wpoos-pro' ),
			'add_channel_member'              => __( 'Add Channel Member', 'mcp-ai-wpoos-pro' ),
			'remove_channel_member'           => __( 'Remove Channel Member', 'mcp-ai-wpoos-pro' ),

			// Interactive elements.
			'send_interactive_message'        => __( 'Send Interactive Message', 'mcp-ai-wpoos-pro' ),
			'handle_callback_query'           => __( 'Handle Callback Query', 'mcp-ai-wpoos-pro' ),

			// User management.
			'get_chat_user_info'              => __( 'Get Chat User Info', 'mcp-ai-wpoos-pro' ),
			'manage_user_permissions'         => __( 'Manage User Permissions', 'mcp-ai-wpoos-pro' ),

			// Analytics.
			'get_chat_analytics'              => __( 'Get Chat Analytics', 'mcp-ai-wpoos-pro' ),
			'track_message_delivery'          => __( 'Track Message Delivery', 'mcp-ai-wpoos-pro' ),

			// Webhooks.
			'configure_chat_webhook'          => __( 'Configure Chat Webhook', 'mcp-ai-wpoos-pro' ),
			'process_webhook_event'           => __( 'Process Webhook Event', 'mcp-ai-wpoos-pro' ),

			// Advanced features.
			'moderate_chat_content'           => __( 'Moderate Chat Content', 'mcp-ai-wpoos-pro' ),
			'auto_translate_message'          => __( 'Auto-Translate Message', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Chat_Channels_Settings_Page();
}
