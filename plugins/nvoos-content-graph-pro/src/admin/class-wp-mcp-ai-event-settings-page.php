<?php
/**
 * Event Settings Page (ecosystem port — Wave F2, PM admin slice B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-event-settings-page.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs — the addon's autoloader skips its copy when
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the cpt-settings-page-base require resolves from the addon's already-ported `src/admin/class-wp-mcp-ai-cpt-settings-page-base.php`.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

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
		$this->page_title  = __( 'Event Management Settings', 'nvoos-content-graph-pro' );
		$this->menu_title  = __( 'Settings', 'nvoos-content-graph-pro' );
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
			__( 'Enable Research & Add', 'nvoos-content-graph-pro' ),
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
			<?php esc_html_e( 'Enable the Research & Add page for event research', 'nvoos-content-graph-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create events using AI assistance.', 'nvoos-content-graph-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Event Management Overview', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'AI-powered event creation and management system. Plan, organize, and manage events with AI assistance for venue selection, scheduling, and attendee management.', 'nvoos-content-graph-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Event Planning: AI-assisted event planning and organization', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Venue Management: Find and manage event venues', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Calendar Integration: Sync events with calendars (iCal/Google)', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Attendee Management: Track and manage event attendees', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Real-time Tracking: Track event metrics and attendance', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Research & Add: AI-powered event research and creation', 'nvoos-content-graph-pro' ); ?></li>
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
			'create_event'             => __( 'Create Event', 'nvoos-content-graph-pro' ),
			'update_event'             => __( 'Update Event', 'nvoos-content-graph-pro' ),
			'get_events'               => __( 'Get Events', 'nvoos-content-graph-pro' ),
			'delete_event'             => __( 'Delete Event', 'nvoos-content-graph-pro' ),
			'real_time_event_tracking' => __( 'Real-time Event Tracking', 'nvoos-content-graph-pro' ),
			'create_event_booking'     => __( 'Create Event Booking (DJ)', 'nvoos-content-graph-pro' ),
			'update_event_details'     => __( 'Update Event Details (DJ)', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Event Management AI features.', 'nvoos-content-graph-pro' ) . '</p>';
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
