<?php
/**
 * WebChat Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for WebChat functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * WebChat Settings Page
 */
class WP_MCP_AI_WebChat_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_webchat_settings';
		$this->post_type   = 'mcp_ai_webchat';
		$this->page_title  = __( 'WebChat Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'webchat-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Render overview tab.
	 *
	 * @since 1.2.0
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview" style="max-width: 1200px;">
			<h2><?php esc_html_e( 'WebChat Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<p><?php esc_html_e( 'Real-time WebRTC-based peer-to-peer video chat integration with room management, participant tracking, and anonymous chat support. Enables decentralized, serverless chat directly on your WordPress site.', 'mcp-ai-wpoos-pro' ); ?></p>

			<div class="toolkit-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
				<h3><?php esc_html_e( '🚀 Quick Start Guide', 'mcp-ai-wpoos-pro' ); ?></h3>
				
				<h4><?php esc_html_e( 'Step 1: Enable WebChat Integration', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p>
					<?php
					$settings_url = admin_url( 'admin.php?page=wp_mcp_ai_settings&tab=tools' );
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'Go to <a href="%s">Settings → NV oOS → Tools & Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable WebChat Integration"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
							esc_url( $settings_url )
						)
					);
					?>
				</p>

				<h4><?php esc_html_e( 'Step 2: Create a WebChat Room', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p>
					<?php
					$add_room_url = admin_url( 'post-new.php?post_type=mcp_ai_webchat' );
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to add room page */
							__( '<a href="%s">Create a new WebChat Room</a> with a title, description, and optional settings like max participants and signaling server URL.', 'mcp-ai-wpoos-pro' ),
							esc_url( $add_room_url )
						)
					);
					?>
				</p>

				<h4><?php esc_html_e( 'Step 3: Configure Room Settings', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'In the Room Details metabox, configure:', 'mcp-ai-wpoos-pro' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Room ID:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Auto-generated unique identifier for the chat room', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Max Participants:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Set capacity limit (2-100)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Signaling Server:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'WebSocket URL for WebRTC signaling (optional)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Status:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Active, Inactive, or Archived', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="toolkit-card" style="background: #f8f9fa; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
				<h3><?php esc_html_e( '📋 Configuration Options', 'mcp-ai-wpoos-pro' ); ?></h3>
				
				<h4><?php esc_html_e( 'Signaling Server Options', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'WebChat requires a signaling server for WebRTC peer discovery and connection setup. You have two options:', 'mcp-ai-wpoos-pro' ); ?></p>
				<ul>
					<li>
						<strong><?php esc_html_e( 'Self-Hosted (Recommended):', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php esc_html_e( ' Uses WordPress REST API + Server-Sent Events for signaling. No external server required. Enable "Use Self-Hosted Signaling" in settings.', 'mcp-ai-wpoos-pro' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'External WebSocket Server:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php esc_html_e( ' Requires a separate WebSocket server. Disable self-hosted signaling and provide the WebSocket URL (e.g., wss://signaling.yoursite.com).', 'mcp-ai-wpoos-pro' ); ?>
					</li>
				</ul>

				<h4><?php esc_html_e( 'Default Settings', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php esc_html_e( 'Configure global defaults in the Settings tab above:', 'mcp-ai-wpoos-pro' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Enable Self-Hosted Signaling:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use WordPress as the WebRTC signaling server (recommended)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'External Signaling Server URL:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Optional external WebSocket URL (only if self-hosted disabled)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Default Max Participants:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Default capacity for new rooms', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Enable Anonymous Chat:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Allow non-authenticated users to join rooms', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Enable WebChat Integration:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Master switch for WebChat features', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Room Configuration Example', 'mcp-ai-wpoos-pro' ); ?></h4>
				<pre style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto; font-size: 13px;">
<strong><?php esc_html_e( 'Room Title:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Product Demo Meeting', 'mcp-ai-wpoos-pro' ); ?>

<strong><?php esc_html_e( 'Room Description:', 'mcp-ai-wpoos-pro' ); ?></strong>
<?php esc_html_e( 'Interactive product demonstration and Q&A session for potential customers.', 'mcp-ai-wpoos-pro' ); ?>

<strong><?php esc_html_e( 'Room Settings:', 'mcp-ai-wpoos-pro' ); ?></strong>
- <?php esc_html_e( 'Max Participants: 20', 'mcp-ai-wpoos-pro' ); ?>

- <?php esc_html_e( 'Status: Active', 'mcp-ai-wpoos-pro' ); ?>

- <?php esc_html_e( 'Anonymous Access: Enabled', 'mcp-ai-wpoos-pro' ); ?></pre>
			</div>

			<div class="toolkit-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
				<h3><?php esc_html_e( '🔧 AI Integration', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p><?php esc_html_e( 'AI assistants can interact with WebChat rooms using the following tools:', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<h4><code>send_webchat_message</code></h4>
				<p><?php esc_html_e( 'Send AI-generated messages to WebChat rooms for moderation, automated responses, or announcements.', 'mcp-ai-wpoos-pro' ); ?></p>
				<pre style="background: #f8f9fa; padding: 15px; border: 1px solid #ddd; overflow-x: auto; font-size: 13px;">
{
  "room_id": "abc123xyz",
  "message": "Welcome! I'm an AI assistant here to help.",
  "sender_name": "Support Bot"
}</pre>

				<h4><code>create_webchat_room</code></h4>
				<p><?php esc_html_e( 'AI can dynamically create rooms based on user requests.', 'mcp-ai-wpoos-pro' ); ?></p>
				<pre style="background: #f8f9fa; padding: 15px; border: 1px solid #ddd; overflow-x: auto; font-size: 13px;">
{
  "title": "Team Standup - Jan 15",
  "description": "Daily team sync meeting",
  "max_participants": 10,
  "status": "active"
}</pre>

				<h4><code>list_webchat_rooms</code></h4>
				<p><?php esc_html_e( 'AI can list available rooms to help users find active chats.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><code>get_webchat_room</code></h4>
				<p><?php esc_html_e( 'Retrieve details about a specific room, including participant count.', 'mcp-ai-wpoos-pro' ); ?></p>

				<h4><code>get_webchat_status</code></h4>
				<p><?php esc_html_e( 'Monitor room status and participant activity in real-time.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<div class="toolkit-card" style="background: #fff3cd; border: 1px solid #ffc107; padding: 20px; margin: 20px 0;">
				<h3><?php esc_html_e( '⚠️ Requirements', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li>
						<strong><?php esc_html_e( 'Browser Extension:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: WebChat GitHub URL */
								__( ' Users need the <a href="%s" target="_blank">WebChat browser extension</a> to participate in P2P chat rooms.', 'mcp-ai-wpoos-pro' ),
								'https://github.com/molvqingtai/WebChat'
							)
						);
						?>
					</li>
					<li>
						<strong><?php esc_html_e( 'HTTPS:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php esc_html_e( ' WebRTC requires a secure HTTPS connection for video/audio streaming.', 'mcp-ai-wpoos-pro' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Modern Browser:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php esc_html_e( ' Chrome, Firefox, Safari, or Edge with WebRTC support.', 'mcp-ai-wpoos-pro' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Signaling Server:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php esc_html_e( ' Self-hosted (WordPress) or external WebSocket server. Self-hosted is recommended and requires no additional setup.', 'mcp-ai-wpoos-pro' ); ?>
					</li>
				</ul>
			</div>

			<div class="toolkit-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
				<h3><?php esc_html_e( '✨ Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Decentralized Chat:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' Peer-to-peer WebRTC communication without server-side message storage', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Room Management:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' Create and manage multiple chat rooms with custom settings', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'WebRTC Video/Audio:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' Browser-based video and audio communication', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Participant Tracking:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' Monitor active participants and enforce room capacity', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Anonymous Access:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' Optional anonymous access for public rooms', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Privacy-First:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' End-to-end encrypted peer-to-peer messages', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'AI-Powered Moderation:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' AI assistants can send messages and moderate rooms', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="toolkit-card" style="background: #f8f9fa; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
				<h3><?php esc_html_e( '💼 Use Cases', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Virtual Meetings:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' Team collaboration and remote standups', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Online Tutoring:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' One-on-one or group educational sessions', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Customer Support:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' Live video consultation and troubleshooting', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Community Events:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' Webinars, Q&A sessions, and virtual meetups', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Product Demos:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' Interactive product demonstrations for prospects', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Healthcare:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( ' Telemedicine and remote patient consultations', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="toolkit-card" style="background: #e7f3ff; border: 1px solid #007cba; padding: 20px; margin: 20px 0;">
				<h3><?php esc_html_e( '🔐 Privacy & Security', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Messages are transmitted peer-to-peer and not stored on servers', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'WebRTC provides end-to-end encryption for all communications', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Room IDs are unique and difficult to guess', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Optional authentication controls via WordPress user accounts', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Participant capacity limits prevent room flooding', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="toolkit-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
				<h3><?php esc_html_e( '📚 Learn More', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: WebChat GitHub URL */
								__( '<a href="%s" target="_blank">WebChat Browser Extension Documentation</a>', 'mcp-ai-wpoos-pro' ),
								'https://github.com/molvqingtai/WebChat'
							)
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: WebRTC info URL */
								__( '<a href="%s" target="_blank">Learn about WebRTC Technology</a>', 'mcp-ai-wpoos-pro' ),
								'https://webrtc.org/'
							)
						);
						?>
					</li>
					<li>
						<?php
						$tools_tab_url = add_query_arg( 'tab', 'tools' );
						echo wp_kses_post(
							sprintf(
								/* translators: %s: Tools tab URL */
								__( '<a href="%s">View Available WebChat Tools</a>', 'mcp-ai-wpoos-pro' ),
								esc_url( $tools_tab_url )
							)
						);
						?>
					</li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Get tools list.
	 *
	 * @since 1.2.0
	 * @return array Tools list with slugs and names.
	 */
	protected function get_tools_list() {
		return array(
			'create_webchat_room' => __( 'Create WebChat Room', 'mcp-ai-wpoos-pro' ),
			'list_webchat_rooms'  => __( 'List WebChat Rooms', 'mcp-ai-wpoos-pro' ),
			'get_webchat_room'    => __( 'Get WebChat Room', 'mcp-ai-wpoos-pro' ),
			'get_webchat_status'  => __( 'Get WebChat Status', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add WebChat-specific settings section.
		add_settings_section(
			$this->option_name . '_defaults_section',
			__( 'Default WebChat Settings', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_defaults_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'enable_self_hosted_signaling',
			__( 'Enable Self-Hosted Signaling', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_self_hosted_signaling_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_signaling_server',
			__( 'External Signaling Server URL', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_signaling_server_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_max_participants',
			__( 'Default Max Participants', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_max_participants_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'enable_anonymous_chat',
			__( 'Enable Anonymous Chat', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_anonymous_chat_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'enable_webchat_integration',
			__( 'Enable WebChat Integration', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_webchat_integration_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);
	}

	/**
	 * Render defaults section description.
	 */
	public function render_defaults_section_description() {
		echo '<p>' . esc_html__( 'Configure default values for WebChat rooms. You can use either self-hosted signaling (built into WordPress) or an external WebSocket server.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render enable self-hosted signaling field.
	 */
	public function render_enable_self_hosted_signaling_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_self_hosted_signaling'] ) ? (bool) $options['enable_self_hosted_signaling'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_self_hosted_signaling]"
				id="enable_self_hosted_signaling"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Use WordPress as the WebRTC signaling server', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php
			echo wp_kses_post(
				__( '<strong>Recommended.</strong> When enabled, WebChat uses WordPress REST API and Server-Sent Events for WebRTC signaling. No external server required. If disabled, you must provide an external WebSocket signaling server URL below.', 'mcp-ai-wpoos-pro' )
			);
			?>
		</p>
		<p class="description">
			<?php
			$url = rest_url( 'mcp-ai/v1/webchat/' );
			echo wp_kses_post(
				sprintf(
					/* translators: %s: REST API endpoint URL */
					__( 'Self-hosted signaling endpoint: <code>%s</code>', 'mcp-ai-wpoos-pro' ),
					esc_url( $url )
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render default signaling server field.
	 */
	public function render_default_signaling_server_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_signaling_server'] ) ? esc_url( $options['default_signaling_server'] ) : '';

		?>
		<input
			type="url"
			name="<?php echo esc_attr( $this->option_name ); ?>[default_signaling_server]"
			id="default_signaling_server"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="wss://signaling.example.com"
		/>
		<p class="description">
			<?php esc_html_e( 'Optional external WebSocket URL for WebRTC signaling server. Only used if self-hosted signaling is disabled.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render default max participants field.
	 */
	public function render_default_max_participants_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_max_participants'] ) ? absint( $options['default_max_participants'] ) : 10;

		?>
		<input
			type="number"
			name="<?php echo esc_attr( $this->option_name ); ?>[default_max_participants]"
			id="default_max_participants"
			value="<?php echo esc_attr( $value ); ?>"
			min="2"
			max="100"
			step="1"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Maximum number of participants allowed in a room. Must be between 2 and 100.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render enable anonymous chat field.
	 */
	public function render_enable_anonymous_chat_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_anonymous_chat'] ) ? (bool) $options['enable_anonymous_chat'] : false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_anonymous_chat]"
				id="enable_anonymous_chat"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Allow anonymous users to join chat rooms', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, rooms can allow anonymous participants without WordPress authentication.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render enable webchat integration field.
	 */
	public function render_enable_webchat_integration_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_webchat_integration'] ) ? (bool) $options['enable_webchat_integration'] : false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_webchat_integration]"
				id="enable_webchat_integration"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable WebChat tools and room management', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, AI assistants can create and manage WebChat rooms.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Call parent sanitization for base fields.
		$sanitized = parent::sanitize_settings( $input );

		// Add WebChat-specific sanitization.
		if ( isset( $input['enable_self_hosted_signaling'] ) ) {
			$sanitized['enable_self_hosted_signaling'] = (bool) $input['enable_self_hosted_signaling'];
		} else {
			// Checkbox not checked.
			$sanitized['enable_self_hosted_signaling'] = false;
		}

		if ( isset( $input['default_signaling_server'] ) ) {
			$sanitized['default_signaling_server'] = esc_url_raw( $input['default_signaling_server'] );
		}

		if ( isset( $input['default_max_participants'] ) ) {
			$max_participants                         = absint( $input['default_max_participants'] );
			$sanitized['default_max_participants'] = max( 2, min( 100, $max_participants ) );
		}

		if ( isset( $input['enable_anonymous_chat'] ) ) {
			$sanitized['enable_anonymous_chat'] = (bool) $input['enable_anonymous_chat'];
		} else {
			// Checkbox not checked.
			$sanitized['enable_anonymous_chat'] = false;
		}

		if ( isset( $input['enable_webchat_integration'] ) ) {
			$sanitized['enable_webchat_integration'] = (bool) $input['enable_webchat_integration'];
		} else {
			// Checkbox not checked.
			$sanitized['enable_webchat_integration'] = false;
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_WebChat_Settings_Page();
