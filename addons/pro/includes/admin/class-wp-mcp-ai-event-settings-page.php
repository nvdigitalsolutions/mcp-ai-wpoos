<?php
/**
 * Event Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Event Management functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Event Settings Page
 */
class WP_MCP_AI_Event_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_event_settings';
		$this->post_type   = 'mcp_ai_event';
		$this->page_title  = __( 'Event Management Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'event-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add event-specific settings.
		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render enable research field.
	 */
	public function render_enable_research_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]"
				id="enable_research"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable the Research & Add page for event research', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create events using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Event Management Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'AI-powered event creation and management system. Plan, organize, and manage events with AI assistance for venue selection, scheduling, and attendee management.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Event Planning: AI-assisted event planning and organization', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Venue Management: Find and manage event venues', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Calendar Integration: Sync events with calendars (iCal/Google)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Attendee Management: Track and manage event attendees', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Real-time Tracking: Track event metrics and attendance', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Research & Add: AI-powered event research and creation', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get tools list for this CPT.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'create_event'                => __( 'Create Event', 'mcp-ai-wpoos-pro' ),
			'update_event'                => __( 'Update Event', 'mcp-ai-wpoos-pro' ),
			'get_events'                  => __( 'Get Events', 'mcp-ai-wpoos-pro' ),
			'delete_event'                => __( 'Delete Event', 'mcp-ai-wpoos-pro' ),
			'real_time_event_tracking'    => __( 'Real-time Event Tracking', 'mcp-ai-wpoos-pro' ),
			'create_event_booking'        => __( 'Create Event Booking (DJ)', 'mcp-ai-wpoos-pro' ),
			'update_event_details'        => __( 'Update Event Details (DJ)', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Event Management AI features.', 'mcp-ai-wpoos-pro' ) . '</p>';
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

		// Add event-specific sanitization.
		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			$sanitized['enable_research'] = false;
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Event_Settings_Page();
