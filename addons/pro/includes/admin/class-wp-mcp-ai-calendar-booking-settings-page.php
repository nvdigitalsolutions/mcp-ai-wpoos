<?php
/**
 * Calendar Booking Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Calendar Booking Toolkit Settings Page Class
 */
class WP_MCP_AI_Calendar_Booking_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'calendar_booking';
		$this->toolkit_name     = __( 'Calendar Booking Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_calendar_booking_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-calendar-booking-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-calendar-alt';

		parent::__construct();
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
			<h2><?php esc_html_e( 'Calendar Booking Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="notice notice-info">
				<p><strong><?php esc_html_e( 'Coming Soon - Phase 2.6', 'mcp-ai-wpoos-pro' ); ?></strong></p>
				<p><?php esc_html_e( 'This toolkit is planned for implementation in Phase 2.6. Tools and features are subject to change.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Comprehensive booking and scheduling system with 12-15 tools for appointment management, availability tracking, and calendar synchronization.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Online Booking: Accept appointments and reservations through WordPress', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Availability Management: Define schedules, block times, and manage resources', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Calendar Sync: Two-way sync with Google Calendar, Outlook, and iCal', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Automated Reminders: Send email and SMS reminders to reduce no-shows', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Resource Scheduling: Manage rooms, equipment, and staff availability', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Payment Integration: Collect deposits or full payment at booking', 'mcp-ai-wpoos-pro' ); ?></li>
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
			<h2><?php esc_html_e( 'Calendar Booking Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Configuration options will be available when this toolkit is implemented in Phase 2.6.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Timezone', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="default_timezone" value="America/New_York" class="regular-text" disabled />
						<p class="description"><?php esc_html_e( 'Default timezone for appointments', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Booking Window (Days)', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="booking_window" value="30" min="1" class="small-text" disabled />
						<p class="description"><?php esc_html_e( 'How far in advance customers can book', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Calendar Sync', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_calendar_sync" value="1" disabled />
							<?php esc_html_e( 'Sync appointments with external calendars', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
			</table>
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
			'create_appointment'        => __( 'Create Appointment', 'mcp-ai-wpoos-pro' ),
			'update_appointment'        => __( 'Update Appointment', 'mcp-ai-wpoos-pro' ),
			'cancel_appointment'        => __( 'Cancel Appointment', 'mcp-ai-wpoos-pro' ),
			'check_availability'        => __( 'Check Availability', 'mcp-ai-wpoos-pro' ),
			'set_availability_schedule' => __( 'Set Availability Schedule', 'mcp-ai-wpoos-pro' ),
			'block_time_slot'           => __( 'Block Time Slot', 'mcp-ai-wpoos-pro' ),
			'sync_google_calendar'      => __( 'Sync Google Calendar', 'mcp-ai-wpoos-pro' ),
			'sync_outlook_calendar'     => __( 'Sync Outlook Calendar', 'mcp-ai-wpoos-pro' ),
			'send_booking_reminder'     => __( 'Send Booking Reminder', 'mcp-ai-wpoos-pro' ),
			'manage_resources'          => __( 'Manage Resources', 'mcp-ai-wpoos-pro' ),
			'generate_booking_report'   => __( 'Generate Booking Report', 'mcp-ai-wpoos-pro' ),
			'export_calendar_ical'      => __( 'Export Calendar (iCal)', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Calendar_Booking_Settings_Page();
}
