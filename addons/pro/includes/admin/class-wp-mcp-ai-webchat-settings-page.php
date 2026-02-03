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
		$this->post_type   = 'mcp_ai_webchat_room';
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
		<h2><?php esc_html_e( 'WebChat Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
		
		<p><?php esc_html_e( 'Real-time WebRTC-based video chat integration with room management, participant tracking, and anonymous chat support.', 'mcp-ai-wpoos-pro' ); ?></p>

		<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Room Creation: Build WebChat rooms with customizable settings', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'WebRTC Integration: Browser-based video and audio communication', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Participant Management: Track active participants and room capacity', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Anonymous Chat: Optional anonymous access for public rooms', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Status Tracking: Monitor active rooms and participant counts', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Customizable Signaling: Configure signaling server URLs', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Virtual meetings and team collaboration', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Online tutoring and educational sessions', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Customer support and consultation calls', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Community events and webinars', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>
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
			'default_signaling_server',
			__( 'Default Signaling Server URL', 'mcp-ai-wpoos-pro' ),
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
		echo '<p>' . esc_html__( 'Configure default values for WebChat rooms.', 'mcp-ai-wpoos-pro' ) . '</p>';
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
			<?php esc_html_e( 'Default WebSocket URL for WebRTC signaling server.', 'mcp-ai-wpoos-pro' ); ?>
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
