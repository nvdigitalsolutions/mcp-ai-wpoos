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
	 * Render overview tab
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'Chat Channels Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Enterprise-grade chat channel integration toolkit with 21 specialized tools for managing communications across Telegram, WhatsApp, Slack, Discord, Microsoft Teams, and Facebook Messenger.', 'mcp-ai-wpoos-pro' ); ?></p>
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
			<?php $this->render_messenger_config(); ?>

			<h2 style="margin-top: 40px;"><?php esc_html_e( 'Global Settings', 'mcp-ai-wpoos-pro' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default AI Assistant', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="default_assistant" class="regular-text">
							<option value=""><?php esc_html_e( '-- Select Assistant --', 'mcp-ai-wpoos-pro' ); ?></option>
							<?php
							$assistants = get_posts(
								array(
									'post_type'      => 'mcp_ai_assistant',
									'posts_per_page' => -1,
									'post_status'    => 'publish',
								)
							);
							foreach ( $assistants as $assistant ) :
								?>
								<option value="<?php echo esc_attr( $assistant->ID ); ?>">
									<?php echo esc_html( $assistant->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Default AI assistant for handling chat channel messages', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Logging', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_logging" value="1" />
							<?php esc_html_e( 'Log all chat channel activities for debugging', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rate Limiting', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_rate_limiting" value="1" checked />
							<?php esc_html_e( 'Enable automatic rate limiting to prevent API quota exhaustion', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook Security', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="verify_webhook_signatures" value="1" checked />
							<?php esc_html_e( 'Verify webhook signatures (recommended for security)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
			</table>
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
				</table>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://core.telegram.org/bots" target="_blank"><?php esc_html_e( 'Telegram Bot API Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://core.telegram.org/bots/api" target="_blank"><?php esc_html_e( 'API Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
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
	 * Render Facebook Messenger configuration section
	 */
	protected function render_messenger_config() {
		?>
		<div class="platform-config">
			<div class="platform-config-header">
				<h3>📘 <?php esc_html_e( 'Facebook Messenger Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			</div>
			<div class="platform-config-content">
				<p><?php esc_html_e( 'Facebook Messenger integration enables page messaging with quick replies and persistent menus.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<h4><?php esc_html_e( 'Setup Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ol>
					<li><?php esc_html_e( 'Go to Facebook Developers and create a new app', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Add Messenger product to your app', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Generate a Page Access Token', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Subscribe to webhook events', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Page Access Token', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" name="messenger_page_access_token" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Your Facebook Page access token', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'App Secret', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="password" name="messenger_app_secret" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Your Facebook app secret for signature verification', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verify Token', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" name="messenger_verify_token" class="regular-text" placeholder="<?php esc_attr_e( 'Generate a secure token', 'mcp-ai-wpoos-pro' ); ?>" />
							<p class="description"><?php esc_html_e( 'Use this when setting up webhook subscription', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<code><?php echo esc_html( home_url( '/wp-json/mcp-ai/v1/webhooks/messenger' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Configure as Callback URL in Messenger settings', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Required Webhook Events', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="code-snippet">messages, messaging_postbacks, messaging_optins, message_deliveries, message_reads</div>

				<h4><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><a href="https://developers.facebook.com/docs/messenger-platform" target="_blank"><?php esc_html_e( 'Messenger Platform Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
					<li><a href="https://developers.facebook.com/docs/messenger-platform/send-messages" target="_blank"><?php esc_html_e( 'Send API Reference', 'mcp-ai-wpoos-pro' ); ?></a></li>
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
