<?php
/**
 * Chat Channels – Top-Level Admin Menu
 *
 * Registers a dedicated "Chat Channels" top-level WordPress admin menu with
 * three sub-pages:
 *
 *  • Inbox        – Unified multi-channel, multi-agent conversation view.
 *  • Contacts     – CRM-style contact list with tags and status management.
 *  • Automation   – Automation rules and human-takeover configuration.
 *  • Settings     – Redirects to the existing Chat Channels Toolkit settings.
 *
 * The menu is only visible when the Chat Channels Toolkit is enabled and the
 * site is running the Pro version of the plugin.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Channels top-level admin menu handler.
 */
class WP_MCP_AI_Chat_Channels_Menu {

	/**
	 * Top-level menu slug.
	 */
	const MENU_SLUG = 'wp-mcp-ai-chat-channels';

	/**
	 * Capability required to access the menu.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Constructor – hooks into WordPress admin.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register top-level menu and sub-pages.
	 */
	public function register_menus() {
		// Top-level Chat Channels menu.
		add_menu_page(
			__( 'Chat Channels', 'mcp-ai-wpoos-pro' ),
			__( 'Chat Channels', 'mcp-ai-wpoos-pro' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_inbox_page' ),
			'dashicons-format-chat',
			58 // After WooCommerce (55) and E-Commerce Toolkit (56).
		);

		// Inbox (default – same callback as top-level to avoid duplicate entry).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Inbox', 'mcp-ai-wpoos-pro' ),
			__( 'Inbox', 'mcp-ai-wpoos-pro' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_inbox_page' )
		);

		// Contacts / CRM.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Contacts', 'mcp-ai-wpoos-pro' ),
			__( 'Contacts', 'mcp-ai-wpoos-pro' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-contacts',
			array( $this, 'render_contacts_page' )
		);

		// Automation rules.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Automation', 'mcp-ai-wpoos-pro' ),
			__( 'Automation', 'mcp-ai-wpoos-pro' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-automation',
			array( $this, 'render_automation_page' )
		);

		// Settings – redirect to existing Chat Channels Toolkit settings page.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'mcp-ai-wpoos-pro' ),
			__( 'Settings', 'mcp-ai-wpoos-pro' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-settings',
			array( $this, 'render_settings_redirect' )
		);
	}

	/**
	 * Enqueue CSS and JS assets for Chat Channels admin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$chat_channel_hooks = array(
			'toplevel_page_' . self::MENU_SLUG,
			self::MENU_SLUG . '_page_' . self::MENU_SLUG . '-contacts',
			self::MENU_SLUG . '_page_' . self::MENU_SLUG . '-automation',
		);

		if ( ! in_array( $hook, $chat_channel_hooks, true ) ) {
			return;
		}

		// Inbox CSS.
		$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/chat-channels-inbox.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-chat-channels-inbox',
				WP_MCP_AI_PRO_URL . 'assets/css/chat-channels-inbox.css',
				array(),
				WP_MCP_AI_PRO_VERSION
			);
		}

		// Inbox JS.
		$js_file = WP_MCP_AI_PRO_PATH . 'assets/js/chat-channels-inbox.js';
		if ( file_exists( $js_file ) ) {
			wp_enqueue_script(
				'wp-mcp-ai-chat-channels-inbox',
				WP_MCP_AI_PRO_URL . 'assets/js/chat-channels-inbox.js',
				array( 'jquery', 'wp-api-fetch' ),
				WP_MCP_AI_PRO_VERSION,
				true
			);

			wp_localize_script(
				'wp-mcp-ai-chat-channels-inbox',
				'wpMcpAiChatChannels',
				array(
					'restUrl'   => esc_url_raw( rest_url( 'mcp-ai-pro/v1/chat-channels' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'i18n'      => array(
						'loading'         => __( 'Loading…', 'mcp-ai-wpoos-pro' ),
						'noConversations' => __( 'No conversations found.', 'mcp-ai-wpoos-pro' ),
						'sendReply'       => __( 'Send Reply', 'mcp-ai-wpoos-pro' ),
						'humanTakeover'   => __( 'Human Takeover', 'mcp-ai-wpoos-pro' ),
						'resumeAI'        => __( 'Resume AI', 'mcp-ai-wpoos-pro' ),
						'resolve'         => __( 'Resolve', 'mcp-ai-wpoos-pro' ),
						'addTag'          => __( 'Add Tag', 'mcp-ai-wpoos-pro' ),
						'confirmResolve'  => __( 'Mark this conversation as resolved?', 'mcp-ai-wpoos-pro' ),
						'replySent'       => __( 'Reply sent.', 'mcp-ai-wpoos-pro' ),
						'errorSending'    => __( 'Failed to send reply. Please try again.', 'mcp-ai-wpoos-pro' ),
					),
					'channelLabels' => array(
						'whatsapp'    => 'WhatsApp',
						'telegram'    => 'Telegram',
						'slack'       => 'Slack',
						'discord'     => 'Discord',
						'teams'       => 'Microsoft Teams',
						'messenger'   => 'Facebook Messenger',
						'google_chat' => 'Google Chat',
						'twitter'     => 'Twitter/X',
						'webchat'     => 'WebChat',
					),
				)
			);
		}
	}

	// =========================================================================
	// Page renderers
	// =========================================================================

	/**
	 * Render the Inbox page.
	 */
	public function render_inbox_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$this->render_page_header( __( 'Chat Channels Inbox', 'mcp-ai-wpoos-pro' ), 'inbox' );
		?>
		<div id="wp-mcp-ai-chat-channels-app" class="wp-mcp-ai-chat-channels-wrap">

			<!-- Toolbar: channel filter + search -->
			<div class="cc-toolbar">
				<div class="cc-filters">
					<select id="cc-filter-channel" class="cc-select">
						<option value=""><?php esc_html_e( 'All Channels', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="whatsapp">WhatsApp</option>
						<option value="telegram">Telegram</option>
						<option value="slack">Slack</option>
						<option value="discord">Discord</option>
						<option value="teams"><?php esc_html_e( 'Microsoft Teams', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="messenger"><?php esc_html_e( 'Facebook Messenger', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="google_chat"><?php esc_html_e( 'Google Chat', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="twitter">Twitter/X</option>
						<option value="webchat">WebChat</option>
					</select>
					<select id="cc-filter-status" class="cc-select">
						<option value=""><?php esc_html_e( 'All Statuses', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="new"><?php esc_html_e( 'New', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="active"><?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="resolved"><?php esc_html_e( 'Resolved', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
					<input type="search" id="cc-search" class="cc-input" placeholder="<?php esc_attr_e( 'Search conversations…', 'mcp-ai-wpoos-pro' ); ?>" />
				</div>
				<button id="cc-refresh" class="button"><?php esc_html_e( 'Refresh', 'mcp-ai-wpoos-pro' ); ?></button>
			</div>

			<!-- Main split layout: conversation list | message thread -->
			<div class="cc-layout">

				<!-- Left panel: conversation list -->
				<div class="cc-conversations-panel" id="cc-conversations-panel">
					<div id="cc-conversations-list" class="cc-conversations-list">
						<div class="cc-placeholder"><?php esc_html_e( 'Loading conversations…', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
					<div class="cc-pagination" id="cc-pagination"></div>
				</div>

				<!-- Right panel: active conversation thread -->
				<div class="cc-thread-panel" id="cc-thread-panel">
					<div class="cc-thread-placeholder">
						<span class="dashicons dashicons-format-chat"></span>
						<p><?php esc_html_e( 'Select a conversation to view the message thread.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
					<div id="cc-thread-content" class="cc-thread-content" style="display:none;">
						<!-- Contact header -->
						<div class="cc-thread-header" id="cc-thread-header"></div>
						<!-- Messages -->
						<div class="cc-messages" id="cc-messages"></div>
						<!-- Reply box -->
						<div class="cc-reply-box" id="cc-reply-box">
							<textarea id="cc-reply-text" class="cc-reply-textarea" rows="3" placeholder="<?php esc_attr_e( 'Type a reply…', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
							<div class="cc-reply-actions">
								<button id="cc-send-reply" class="button button-primary"><?php esc_html_e( 'Send Reply', 'mcp-ai-wpoos-pro' ); ?></button>
								<button id="cc-human-takeover-btn" class="button"><?php esc_html_e( 'Human Takeover', 'mcp-ai-wpoos-pro' ); ?></button>
								<button id="cc-resolve-btn" class="button"><?php esc_html_e( 'Resolve', 'mcp-ai-wpoos-pro' ); ?></button>
							</div>
						</div>
					</div>
				</div>

			</div><!-- .cc-layout -->
		</div><!-- #wp-mcp-ai-chat-channels-app -->
		<?php
	}

	/**
	 * Render the Contacts / CRM page.
	 */
	public function render_contacts_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$this->render_page_header( __( 'Chat Contacts & CRM', 'mcp-ai-wpoos-pro' ), 'contacts' );
		?>
		<div id="wp-mcp-ai-contacts-app" class="wp-mcp-ai-chat-channels-wrap">

			<div class="cc-toolbar">
				<div class="cc-filters">
					<select id="cc-contacts-filter-channel" class="cc-select">
						<option value=""><?php esc_html_e( 'All Channels', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="whatsapp">WhatsApp</option>
						<option value="telegram">Telegram</option>
						<option value="slack">Slack</option>
						<option value="discord">Discord</option>
						<option value="teams"><?php esc_html_e( 'Microsoft Teams', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="messenger"><?php esc_html_e( 'Facebook Messenger', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="google_chat"><?php esc_html_e( 'Google Chat', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="twitter">Twitter/X</option>
					</select>
					<select id="cc-contacts-filter-status" class="cc-select">
						<option value=""><?php esc_html_e( 'All Statuses', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="new"><?php esc_html_e( 'New', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="active"><?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="resolved"><?php esc_html_e( 'Resolved', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="blocked"><?php esc_html_e( 'Blocked', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
					<input type="search" id="cc-contacts-search" class="cc-input" placeholder="<?php esc_attr_e( 'Search contacts…', 'mcp-ai-wpoos-pro' ); ?>" />
					<input type="text" id="cc-contacts-filter-tag" class="cc-input" placeholder="<?php esc_attr_e( 'Filter by tag…', 'mcp-ai-wpoos-pro' ); ?>" />
				</div>
				<button id="cc-contacts-refresh" class="button"><?php esc_html_e( 'Refresh', 'mcp-ai-wpoos-pro' ); ?></button>
			</div>

			<div id="cc-contacts-table-wrap">
				<table class="wp-list-table widefat fixed striped cc-contacts-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Channel', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Contact ID', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Tags', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Last Message', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody id="cc-contacts-tbody">
						<tr><td colspan="7"><?php esc_html_e( 'Loading contacts…', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					</tbody>
				</table>
				<div class="cc-pagination" id="cc-contacts-pagination"></div>
			</div>

			<!-- Tag modal -->
			<div id="cc-tag-modal" class="cc-modal" style="display:none;">
				<div class="cc-modal-inner">
					<h3><?php esc_html_e( 'Add Tag', 'mcp-ai-wpoos-pro' ); ?></h3>
					<input type="text" id="cc-tag-input" class="regular-text" placeholder="<?php esc_attr_e( 'Tag name…', 'mcp-ai-wpoos-pro' ); ?>" />
					<div class="cc-modal-actions">
						<button id="cc-tag-save" class="button button-primary"><?php esc_html_e( 'Add Tag', 'mcp-ai-wpoos-pro' ); ?></button>
						<button id="cc-tag-cancel" class="button"><?php esc_html_e( 'Cancel', 'mcp-ai-wpoos-pro' ); ?></button>
					</div>
				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * Render the Automation rules page.
	 */
	public function render_automation_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$this->render_page_header( __( 'Chat Automation Rules', 'mcp-ai-wpoos-pro' ), 'automation' );

		$option_name = 'wp_mcp_ai_chat_channels_automation_rules';

		// Handle form save.
		if ( isset( $_POST['wp_mcp_ai_automation_nonce'] ) ) {
			check_admin_referer( 'wp_mcp_ai_save_automation_rules', 'wp_mcp_ai_automation_nonce' );

			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
			}

			$saved = $this->sanitize_automation_settings( $_POST );
			update_option( $option_name, $saved );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Automation rules saved.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
		}

		$rules = get_option( $option_name, array() );
		$this->render_automation_form( $rules, $option_name );
	}

	/**
	 * Redirect the Settings submenu to the existing Chat Channels Toolkit settings page.
	 */
	public function render_settings_redirect() {
		wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-chat-channels-toolkit-settings' ) );
		exit;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Render a consistent page header with navigation tabs.
	 *
	 * @param string $title Page title.
	 * @param string $active Active tab slug: 'inbox', 'contacts', or 'automation'.
	 */
	protected function render_page_header( $title, $active ) {
		$tabs = array(
			'inbox'      => array(
				'label' => __( 'Inbox', 'mcp-ai-wpoos-pro' ),
				'url'   => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
			),
			'contacts'   => array(
				'label' => __( 'Contacts', 'mcp-ai-wpoos-pro' ),
				'url'   => admin_url( 'admin.php?page=' . self::MENU_SLUG . '-contacts' ),
			),
			'automation' => array(
				'label' => __( 'Automation', 'mcp-ai-wpoos-pro' ),
				'url'   => admin_url( 'admin.php?page=' . self::MENU_SLUG . '-automation' ),
			),
			'settings'   => array(
				'label' => __( 'Settings', 'mcp-ai-wpoos-pro' ),
				'url'   => admin_url( 'admin.php?page=wp-mcp-ai-chat-channels-toolkit-settings' ),
			),
		);
		?>
		<div class="wrap wp-mcp-ai-cc-page-wrap">
			<h1 class="wp-heading-inline">
				<span class="dashicons dashicons-format-chat" style="font-size:1.4em;vertical-align:middle;margin-right:6px;"></span>
				<?php echo esc_html( $title ); ?>
			</h1>
			<nav class="nav-tab-wrapper woo-nav-tab-wrapper cc-nav-tabs" style="margin-top:10px;">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<a href="<?php echo esc_url( $tab['url'] ); ?>"
					   class="nav-tab<?php echo $active === $slug ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<hr class="wp-header-end">
		<?php
	}

	/**
	 * Render the automation settings form.
	 *
	 * @param array  $rules       Saved automation settings.
	 * @param string $option_name WordPress option name.
	 */
	protected function render_automation_form( $rules, $option_name ) {
		$auto_reply_enabled  = ! empty( $rules['auto_reply_enabled'] );
		$human_takeover_kw   = isset( $rules['human_takeover_keywords'] ) ? $rules['human_takeover_keywords'] : 'human,agent,support,help me';
		$ai_resume_kw        = isset( $rules['ai_resume_keywords'] ) ? $rules['ai_resume_keywords'] : 'ai,bot,resume,auto';
		$welcome_msg         = isset( $rules['welcome_message'] ) ? $rules['welcome_message'] : '';
		$default_assistant   = isset( $rules['default_assistant_id'] ) ? $rules['default_assistant_id'] : '';
		$resolve_after_hours = isset( $rules['auto_resolve_after_hours'] ) ? absint( $rules['auto_resolve_after_hours'] ) : 0;

		// Build assistant list for dropdown.
		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<form method="post" action="" class="wp-mcp-ai-automation-form">
			<?php wp_nonce_field( 'wp_mcp_ai_save_automation_rules', 'wp_mcp_ai_automation_nonce' ); ?>

			<div class="cc-settings-section">
				<h2><?php esc_html_e( 'AI Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_reply_enabled" value="1" <?php checked( $auto_reply_enabled ); ?> />
								<?php esc_html_e( 'Automatically reply to inbound messages using the assigned AI assistant', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cc-default-assistant"><?php esc_html_e( 'Default AI Assistant', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<select id="cc-default-assistant" name="default_assistant_id" class="regular-text">
								<option value=""><?php esc_html_e( '— None —', 'mcp-ai-wpoos-pro' ); ?></option>
								<?php foreach ( $assistants as $assistant ) : ?>
									<option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $default_assistant, $assistant->ID ); ?>>
										<?php echo esc_html( $assistant->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The assistant used for auto-replies when no per-connection assistant is configured.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cc-welcome-msg"><?php esc_html_e( 'Welcome Message', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<textarea id="cc-welcome-msg" name="welcome_message" rows="3" class="large-text"><?php echo esc_textarea( $welcome_msg ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Sent automatically to new contacts on their first message. Leave blank to disable.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="cc-settings-section">
				<h2><?php esc_html_e( 'Human Takeover', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'When a contact sends one of the trigger keywords below, AI auto-reply is paused and the conversation is flagged for a human agent. Human agents can reply directly from the Inbox. AI resumes when the contact sends a resume keyword or an agent clicks "Resume AI".', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="cc-takeover-keywords"><?php esc_html_e( 'Takeover Keywords', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="text" id="cc-takeover-keywords" name="human_takeover_keywords" value="<?php echo esc_attr( $human_takeover_kw ); ?>" class="large-text" />
							<p class="description"><?php esc_html_e( 'Comma-separated keywords that trigger human takeover (case-insensitive).', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cc-resume-keywords"><?php esc_html_e( 'AI Resume Keywords', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="text" id="cc-resume-keywords" name="ai_resume_keywords" value="<?php echo esc_attr( $ai_resume_kw ); ?>" class="large-text" />
							<p class="description"><?php esc_html_e( 'Comma-separated keywords that re-enable AI auto-reply for a contact.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="cc-settings-section">
				<h2><?php esc_html_e( 'Auto-Resolve', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="cc-resolve-hours"><?php esc_html_e( 'Auto-Resolve After (hours)', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="number" id="cc-resolve-hours" name="auto_resolve_after_hours" value="<?php echo esc_attr( $resolve_after_hours ); ?>" min="0" max="720" class="small-text" />
							<p class="description"><?php esc_html_e( 'Automatically mark conversations as Resolved after this many hours of inactivity. Set to 0 to disable.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<?php submit_button( __( 'Save Automation Rules', 'mcp-ai-wpoos-pro' ) ); ?>
		</form>
		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Sanitize automation settings submitted via the form.
	 *
	 * @param array $post Raw $_POST data.
	 * @return array Sanitized settings.
	 */
	protected function sanitize_automation_settings( $post ) {
		return array(
			'auto_reply_enabled'      => ! empty( $post['auto_reply_enabled'] ),
			'default_assistant_id'    => isset( $post['default_assistant_id'] ) ? absint( $post['default_assistant_id'] ) : 0,
			'welcome_message'         => isset( $post['welcome_message'] ) ? sanitize_textarea_field( wp_unslash( $post['welcome_message'] ) ) : '',
			'human_takeover_keywords' => isset( $post['human_takeover_keywords'] ) ? sanitize_text_field( wp_unslash( $post['human_takeover_keywords'] ) ) : '',
			'ai_resume_keywords'      => isset( $post['ai_resume_keywords'] ) ? sanitize_text_field( wp_unslash( $post['ai_resume_keywords'] ) ) : '',
			'auto_resolve_after_hours' => isset( $post['auto_resolve_after_hours'] ) ? absint( $post['auto_resolve_after_hours'] ) : 0,
		);
	}
}
