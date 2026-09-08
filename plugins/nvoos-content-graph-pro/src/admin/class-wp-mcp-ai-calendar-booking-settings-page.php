<?php
/**
 * Calendar booking admin page (ecosystem port — Wave F2, calendar admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-calendar-booking-settings-page.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swap with the
 * `src/` path root (toolkit-settings-base require).
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

require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Calendar Booking Toolkit Settings Page Class
 */
class WP_MCP_AI_Calendar_Booking_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'calendar_booking';
		$this->toolkit_name     = __( 'Calendar Booking Toolkit', 'nvoos-content-graph-pro' );
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
			<h2><?php esc_html_e( 'Calendar Booking Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="notice notice-info">
				<p><strong><?php esc_html_e( 'Coming Soon - Phase 2.6', 'nvoos-content-graph-pro' ); ?></strong></p>
				<p><?php esc_html_e( 'This toolkit is planned for implementation in Phase 2.6. Tools and features are subject to change.', 'nvoos-content-graph-pro' ); ?></p>
			</div>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Comprehensive booking and scheduling system with 12-15 tools for appointment management, availability tracking, and calendar synchronization.', 'nvoos-content-graph-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Online Booking: Accept appointments and reservations through WordPress', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Availability Management: Define schedules, block times, and manage resources', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Calendar Sync: Two-way sync with Google Calendar, Outlook, and iCal', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Automated Reminders: Send email and SMS reminders to reduce no-shows', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Resource Scheduling: Manage rooms, equipment, and staff availability', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Payment Integration: Collect deposits or full payment at booking', 'nvoos-content-graph-pro' ); ?></li>
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
			<h2><?php esc_html_e( 'Calendar Booking Toolkit Configuration', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Configuration options will be available when this toolkit is implemented in Phase 2.6.', 'nvoos-content-graph-pro' ); ?></p>
			</div>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Timezone', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<input type="text" name="default_timezone" value="America/New_York" class="regular-text" disabled />
						<p class="description"><?php esc_html_e( 'Default timezone for appointments', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Booking Window (Days)', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<input type="number" name="booking_window" value="30" min="1" class="small-text" disabled />
						<p class="description"><?php esc_html_e( 'How far in advance customers can book', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Calendar Sync', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_calendar_sync" value="1" disabled />
							<?php esc_html_e( 'Sync appointments with external calendars', 'nvoos-content-graph-pro' ); ?>
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
			'create_appointment'        => __( 'Create Appointment', 'nvoos-content-graph-pro' ),
			'update_appointment'        => __( 'Update Appointment', 'nvoos-content-graph-pro' ),
			'cancel_appointment'        => __( 'Cancel Appointment', 'nvoos-content-graph-pro' ),
			'check_availability'        => __( 'Check Availability', 'nvoos-content-graph-pro' ),
			'set_availability_schedule' => __( 'Set Availability Schedule', 'nvoos-content-graph-pro' ),
			'block_time_slot'           => __( 'Block Time Slot', 'nvoos-content-graph-pro' ),
			'sync_google_calendar'      => __( 'Sync Google Calendar', 'nvoos-content-graph-pro' ),
			'sync_outlook_calendar'     => __( 'Sync Outlook Calendar', 'nvoos-content-graph-pro' ),
			'send_booking_reminder'     => __( 'Send Booking Reminder', 'nvoos-content-graph-pro' ),
			'manage_resources'          => __( 'Manage Resources', 'nvoos-content-graph-pro' ),
			'generate_booking_report'   => __( 'Generate Booking Report', 'nvoos-content-graph-pro' ),
			'export_calendar_ical'      => __( 'Export Calendar (iCal)', 'nvoos-content-graph-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Calendar_Booking_Settings_Page();
}
