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
	 * Actual WordPress hook names returned by add_submenu_page().
	 *
	 * Stored during register_menus() so enqueue_assets() and
	 * current_page_slug() can compare against the real hooks
	 * (which use sanitize_title(menu_title) as prefix, not the raw MENU_SLUG).
	 *
	 * @var array<string,string> Map of page-key => hook-suffix.
	 */
	protected $registered_hooks = array();

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
		// Top-level Chat Channels menu – lands on the Dashboard.
		add_menu_page(
			__( 'Chat Channels', 'mcp-ai-wpoos-pro' ),
			__( 'Chat Channels', 'mcp-ai-wpoos-pro' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-format-chat',
			58 // After WooCommerce (55) and E-Commerce Toolkit (56).
		);

		// Dashboard (default top-level).
		$this->registered_hooks['dashboard'] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'mcp-ai-wpoos-pro' ),
			__( 'Dashboard', 'mcp-ai-wpoos-pro' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		// Inbox – unified multi-channel conversation view.
		$this->registered_hooks['inbox'] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Inbox', 'mcp-ai-wpoos-pro' ),
			__( 'Inbox', 'mcp-ai-wpoos-pro' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-inbox',
			array( $this, 'render_inbox_page' )
		);

		// Contacts / CRM.
		$this->registered_hooks['contacts'] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Contacts', 'mcp-ai-wpoos-pro' ),
			__( 'Contacts', 'mcp-ai-wpoos-pro' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-contacts',
			array( $this, 'render_contacts_page' )
		);

		// Automation rules.
		$this->registered_hooks['automation'] = add_submenu_page(
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
	 * All admin page hook suffixes managed by this class.
	 *
	 * @return array
	 */
	protected function get_page_hooks() {
		$hooks = array( 'toplevel_page_' . self::MENU_SLUG );

		// Use the actual hook names WordPress registered (based on sanitize_title of
		// the menu title, which differs from the raw MENU_SLUG).
		if ( ! empty( $this->registered_hooks ) ) {
			foreach ( $this->registered_hooks as $hook ) {
				if ( $hook && ! in_array( $hook, $hooks, true ) ) {
					$hooks[] = $hook;
				}
			}
		}

		return $hooks;
	}

	/**
	 * Enqueue CSS and JS assets for Chat Channels admin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->get_page_hooks(), true ) ) {
			return;
		}

		// Shared CSS for all Chat Channels pages.
		$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/chat-channels-inbox.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-chat-channels',
				WP_MCP_AI_PRO_URL . 'assets/css/chat-channels-inbox.css',
				array(),
				WP_MCP_AI_PRO_VERSION
			);
		}

		// Chart.js – loaded only on the Dashboard page.
		if ( 'toplevel_page_' . self::MENU_SLUG === $hook ) {
			wp_enqueue_script(
				'chartjs',
				'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
				array(),
				'4.4.0',
				true
			);
		}

		// Inbox JS (also powers the dashboard stats fetch).
		$js_file = WP_MCP_AI_PRO_PATH . 'assets/js/chat-channels-inbox.js';
		if ( file_exists( $js_file ) ) {
			wp_enqueue_script(
				'wp-mcp-ai-chat-channels',
				WP_MCP_AI_PRO_URL . 'assets/js/chat-channels-inbox.js',
				array( 'jquery', 'wp-api-fetch' ),
				WP_MCP_AI_PRO_VERSION,
				true
			);

			wp_localize_script(
				'wp-mcp-ai-chat-channels',
				'wpMcpAiChatChannels',
				array(
					'restUrl'       => esc_url_raw( rest_url( 'mcp-ai-pro/v1/chat-channels' ) ),
					'nonce'         => wp_create_nonce( 'wp_rest' ),
					'currentPage'   => $this->current_page_slug( $hook ),
					'i18n'          => array(
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
						'allChannels'     => __( 'All Channels', 'mcp-ai-wpoos-pro' ),
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

	/**
	 * Map a hook suffix to a short page slug for JS.
	 *
	 * @param string $hook Hook suffix.
	 * @return string
	 */
	protected function current_page_slug( $hook ) {
		// Map stored hook names (from add_submenu_page return values) to JS page slugs.
		$map = array(
			'toplevel_page_' . self::MENU_SLUG => 'dashboard',
		);

		foreach ( array( 'inbox', 'contacts', 'automation' ) as $key ) {
			if ( isset( $this->registered_hooks[ $key ] ) && $this->registered_hooks[ $key ] ) {
				$map[ $this->registered_hooks[ $key ] ] = $key;
			}
		}

		return isset( $map[ $hook ] ) ? $map[ $hook ] : '';
	}

	// =========================================================================
	// Page renderers
	// =========================================================================

	/**
	 * Render the Dashboard analytics page (Chart.js).
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$this->render_page_header( __( 'Chat Channels Dashboard', 'mcp-ai-wpoos-pro' ), 'dashboard' );

		// Fetch summary stats directly from DB.
		$stats = $this->get_dashboard_stats();
		?>
		<div class="wp-mcp-ai-chat-channels-wrap">

			<!-- KPI row -->
			<div class="cc-kpi-row">
				<div class="cc-kpi-card">
					<span class="cc-kpi-value"><?php echo esc_html( number_format_i18n( $stats['total_messages_7d'] ) ); ?></span>
					<span class="cc-kpi-label"><?php esc_html_e( 'Messages (7 days)', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="cc-kpi-card">
					<span class="cc-kpi-value"><?php echo esc_html( number_format_i18n( $stats['total_contacts'] ) ); ?></span>
					<span class="cc-kpi-label"><?php esc_html_e( 'Total Contacts', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="cc-kpi-card">
					<span class="cc-kpi-value"><?php echo esc_html( number_format_i18n( $stats['open_conversations'] ) ); ?></span>
					<span class="cc-kpi-label"><?php esc_html_e( 'Open Conversations', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="cc-kpi-card">
					<span class="cc-kpi-value"><?php echo esc_html( number_format_i18n( $stats['human_takeover_count'] ) ); ?></span>
					<span class="cc-kpi-label"><?php esc_html_e( 'Human Takeovers', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="cc-kpi-card">
					<span class="cc-kpi-value"><?php echo esc_html( number_format_i18n( $stats['inbound_today'] ) ); ?></span>
					<span class="cc-kpi-label"><?php esc_html_e( 'Inbound Today', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
			</div>

			<!-- Charts row -->
			<div class="cc-charts-row">
				<!-- 7-day message volume bar chart -->
				<div class="cc-chart-card cc-chart-card--wide">
					<h3><?php esc_html_e( 'Message Volume – Last 7 Days', 'mcp-ai-wpoos-pro' ); ?></h3>
					<canvas id="cc-volume-chart" height="120"></canvas>
				</div>
				<!-- Channel distribution doughnut -->
				<div class="cc-chart-card">
					<h3><?php esc_html_e( 'Messages by Channel', 'mcp-ai-wpoos-pro' ); ?></h3>
					<canvas id="cc-channel-chart" height="200"></canvas>
				</div>
				<!-- Inbound vs outbound doughnut -->
				<div class="cc-chart-card">
					<h3><?php esc_html_e( 'Inbound vs Outbound (7 days)', 'mcp-ai-wpoos-pro' ); ?></h3>
					<canvas id="cc-direction-chart" height="200"></canvas>
				</div>
			</div>

			<!-- Quick-nav links -->
			<div class="cc-quick-nav">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-inbox' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Open Inbox', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-contacts' ) ); ?>" class="button">
					<?php esc_html_e( 'Manage Contacts', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-automation' ) ); ?>" class="button">
					<?php esc_html_e( 'Automation Rules', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</div>

		</div>

		<script>
		(function() {
			if ( typeof Chart === 'undefined' ) { return; }

			// --- 7-day volume bar chart ---
			var volumeData = <?php echo wp_json_encode( $stats['volume_by_day'] ); ?>;
			new Chart( document.getElementById('cc-volume-chart'), {
				type: 'bar',
				data: {
					labels: volumeData.labels,
					datasets: [{
						label: '<?php echo esc_js( __( 'Messages', 'mcp-ai-wpoos-pro' ) ); ?>',
						data: volumeData.counts,
						backgroundColor: '#4f46e5',
						borderRadius: 4,
					}]
				},
				options: {
					responsive: true,
					plugins: { legend: { display: false } },
					scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
				}
			});

			// --- Channel doughnut chart ---
			var channelData = <?php echo wp_json_encode( $stats['by_channel'] ); ?>;
			if ( channelData.labels.length ) {
				new Chart( document.getElementById('cc-channel-chart'), {
					type: 'doughnut',
					data: {
						labels: channelData.labels,
						datasets: [{
							data: channelData.counts,
							backgroundColor: ['#4f46e5','#22c55e','#f59e0b','#ef4444','#06b6d4','#8b5cf6','#ec4899','#14b8a6','#f97316'],
						}]
					},
					options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
				});
			}

			// --- Inbound / Outbound doughnut ---
			var dirData = <?php echo wp_json_encode( $stats['by_direction'] ); ?>;
			new Chart( document.getElementById('cc-direction-chart'), {
				type: 'doughnut',
				data: {
					labels: ['<?php echo esc_js( __( 'Inbound', 'mcp-ai-wpoos-pro' ) ); ?>','<?php echo esc_js( __( 'Outbound', 'mcp-ai-wpoos-pro' ) ); ?>'],
					datasets: [{
						data: [ dirData.inbound, dirData.outbound ],
						backgroundColor: ['#22c55e', '#4f46e5'],
					}]
				},
				options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
			});
		})();
		</script>
		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Render the Inbox page with per-channel tabs.
	 */
	public function render_inbox_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mcp-ai-wpoos-pro' ) );
		}

		// Active channel tab from query string (defaults to 'all').
		$active_channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->render_page_header( __( 'Chat Channels Inbox', 'mcp-ai-wpoos-pro' ), 'inbox' );
		?>
		<div id="wp-mcp-ai-chat-channels-app" class="wp-mcp-ai-chat-channels-wrap">

			<!-- Per-channel tab strip -->
			<div class="cc-channel-tabs">
				<?php
				$channel_tabs = array(
					'all'         => __( 'All', 'mcp-ai-wpoos-pro' ),
					'whatsapp'    => 'WhatsApp',
					'telegram'    => 'Telegram',
					'slack'       => 'Slack',
					'discord'     => 'Discord',
					'teams'       => 'Microsoft Teams',
					'messenger'   => 'Facebook Messenger',
					'google_chat' => 'Google Chat',
					'twitter'     => 'Twitter/X',
					'webchat'     => 'WebChat',
				);
				foreach ( $channel_tabs as $slug => $label ) :
					$url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-inbox' . ( 'all' === $slug ? '' : '&channel=' . $slug ) );
					?>
					<a href="<?php echo esc_url( $url ); ?>"
					   class="cc-channel-tab<?php echo $active_channel === $slug ? ' cc-channel-tab--active' : ''; ?>"
					   data-channel="<?php echo esc_attr( $slug ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</div>

			<!-- Toolbar: status filter + search -->
			<div class="cc-toolbar">
				<div class="cc-filters">
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
						<!-- Contact header with channel badge -->
						<div class="cc-thread-header" id="cc-thread-header"></div>
						<!-- Messages -->
						<div class="cc-messages" id="cc-messages"></div>
						<!-- Reply box – hidden when human takeover is off for this contact -->
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

			<!-- Hidden: active channel for JS -->
			<input type="hidden" id="cc-active-channel" value="<?php echo esc_attr( 'all' === $active_channel ? '' : $active_channel ); ?>" />

		</div><!-- #wp-mcp-ai-chat-channels-app -->
		</div><!-- .wrap -->
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
		</div><!-- .wrap -->
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
		echo '</div><!-- .wrap -->';
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

	// =========================================================================
	// Dashboard statistics
	// =========================================================================

	/**
	 * Collect summary statistics from the CCT tables for the Dashboard.
	 *
	 * All queries are direct DB reads; the Dashboard is only visible to
	 * administrators so this is acceptable.
	 *
	 * @return array {
	 *   @type int   $total_messages_7d  Messages in the last 7 days.
	 *   @type int   $total_contacts     Total stored contacts.
	 *   @type int   $open_conversations Contacts with crm_status != 'resolved'.
	 *   @type int   $human_takeover_count Contacts with human_takeover = 1.
	 *   @type int   $inbound_today      Inbound messages since midnight.
	 *   @type array $volume_by_day      { labels: string[], counts: int[] } for 7 days.
	 *   @type array $by_channel         { labels: string[], counts: int[] }.
	 *   @type array $by_direction       { inbound: int, outbound: int }.
	 * }
	 */
	protected function get_dashboard_stats() {
		global $wpdb;

		$default = array(
			'total_messages_7d'    => 0,
			'total_contacts'       => 0,
			'open_conversations'   => 0,
			'human_takeover_count' => 0,
			'inbound_today'        => 0,
			'volume_by_day'        => array( 'labels' => array(), 'counts' => array() ),
			'by_channel'           => array( 'labels' => array(), 'counts' => array() ),
			'by_direction'         => array( 'inbound' => 0, 'outbound' => 0 ),
		);

		$msg_table  = class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ? WP_MCP_AI_Channel_Messages_CCT::get_table_name() : '';
		$con_table  = class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ? WP_MCP_AI_Channel_Contacts_CCT::get_table_name() : '';

		$seven_days_ago = time() - ( 7 * DAY_IN_SECONDS );
		$today_start    = mktime( 0, 0, 0 );

		// --- messages CCT queries ---
		if ( $msg_table ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$default['total_messages_7d'] = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$msg_table} WHERE message_timestamp >= %d", $seven_days_ago )
			);

			$default['inbound_today'] = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$msg_table} WHERE direction = 'inbound' AND message_timestamp >= %d", $today_start )
			);

			// 7-day volume – one row per day.
			$volume_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE(FROM_UNIXTIME(message_timestamp)) AS day, COUNT(*) AS cnt
					 FROM {$msg_table}
					 WHERE message_timestamp >= %d
					 GROUP BY day
					 ORDER BY day ASC",
					$seven_days_ago
				),
				ARRAY_A
			);

			$vol_labels = array();
			$vol_counts = array();
			foreach ( (array) $volume_rows as $row ) {
				$vol_labels[] = isset( $row['day'] ) ? $row['day'] : '';
				$vol_counts[] = isset( $row['cnt'] ) ? (int) $row['cnt'] : 0;
			}
			$default['volume_by_day'] = array( 'labels' => $vol_labels, 'counts' => $vol_counts );

			// By channel.
			$channel_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT channel, COUNT(*) AS cnt FROM {$msg_table} WHERE message_timestamp >= %d GROUP BY channel ORDER BY cnt DESC",
					$seven_days_ago
				),
				ARRAY_A
			);

			$ch_labels = array();
			$ch_counts = array();
			$channel_map = array(
				'whatsapp'    => 'WhatsApp',
				'telegram'    => 'Telegram',
				'slack'       => 'Slack',
				'discord'     => 'Discord',
				'teams'       => 'Microsoft Teams',
				'messenger'   => 'Facebook Messenger',
				'google_chat' => 'Google Chat',
				'twitter'     => 'Twitter/X',
				'webchat'     => 'WebChat',
			);
			foreach ( (array) $channel_rows as $row ) {
				$slug        = isset( $row['channel'] ) ? $row['channel'] : '';
				$ch_labels[] = isset( $channel_map[ $slug ] ) ? $channel_map[ $slug ] : ucfirst( $slug );
				$ch_counts[] = isset( $row['cnt'] ) ? (int) $row['cnt'] : 0;
			}
			$default['by_channel'] = array( 'labels' => $ch_labels, 'counts' => $ch_counts );

			// Inbound vs outbound.
			$dir_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT direction, COUNT(*) AS cnt FROM {$msg_table} WHERE message_timestamp >= %d GROUP BY direction",
					$seven_days_ago
				),
				ARRAY_A
			);
			foreach ( (array) $dir_rows as $row ) {
				$dir = isset( $row['direction'] ) ? $row['direction'] : '';
				if ( 'inbound' === $dir ) {
					$default['by_direction']['inbound'] = (int) $row['cnt'];
				} elseif ( 'outbound' === $dir ) {
					$default['by_direction']['outbound'] = (int) $row['cnt'];
				}
			}
			// phpcs:enable
		}

		// --- contacts CCT queries ---
		if ( $con_table ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$default['total_contacts'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$con_table} WHERE cct_status = 'publish'" );

			$default['open_conversations'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$con_table} WHERE cct_status = 'publish' AND crm_status != 'resolved'" );

			$default['human_takeover_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$con_table} WHERE cct_status = 'publish' AND human_takeover = 1" );
			// phpcs:enable
		}

		return $default;
	}

	/**
	 * Render a consistent page header with navigation tabs.
	 *
	 * @param string $title  Page title.
	 * @param string $active Active tab slug: 'dashboard', 'inbox', 'contacts', or 'automation'.
	 */
	protected function render_page_header( $title, $active ) {
		$tabs = array(
			'dashboard'  => array(
				'label' => __( 'Dashboard', 'mcp-ai-wpoos-pro' ),
				'url'   => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
			),
			'inbox'      => array(
				'label' => __( 'Inbox', 'mcp-ai-wpoos-pro' ),
				'url'   => admin_url( 'admin.php?page=' . self::MENU_SLUG . '-inbox' ),
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
