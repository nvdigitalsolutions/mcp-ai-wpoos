<?php
/**
 * DJ Management Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * DJ Management Toolkit Settings Page Class
 */
class WP_MCP_AI_DJ_Management_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'dj_management';
		$this->toolkit_name     = __( 'DJ Management Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_dj_management_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-dj-management-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-controls-play';

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
			<h2><?php esc_html_e( 'DJ Management Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="notice notice-info">
				<p><strong><?php esc_html_e( 'Coming Soon - Phase 2.7', 'mcp-ai-wpoos-pro' ); ?></strong></p>
				<p><?php esc_html_e( 'This toolkit is planned for implementation in Phase 2.7. Tools and features are subject to change.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Professional DJ business management toolkit with 15-18 tools for equipment tracking, playlist management, event scheduling, and client management.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Equipment Inventory: Track gear, maintenance schedules, and replacement needs', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Playlist Builder: Create, organize, and share playlists with clients', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Event Calendar: Schedule gigs, block dates, and manage bookings', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Client Database: Store client preferences, song requests, and contact info', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Music Library: Organize tracks by genre, BPM, energy level, and era', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Contract Management: Generate contracts, track deposits, and manage payments', 'mcp-ai-wpoos-pro' ); ?></li>
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
			<h2><?php esc_html_e( 'DJ Management Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Configuration options will be available when this toolkit is implemented in Phase 2.7.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'DJ Business Name', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="business_name" value="" class="regular-text" disabled />
						<p class="description"><?php esc_html_e( 'Your DJ business name for contracts and invoices', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Event Duration (Hours)', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="default_event_duration" value="4" min="1" max="24" class="small-text" disabled />
						<p class="description"><?php esc_html_e( 'Default duration for new events', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Music Library Sync', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_music_sync" value="1" disabled />
							<?php esc_html_e( 'Sync with Spotify, Apple Music, or local music library', 'mcp-ai-wpoos-pro' ); ?>
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
			'add_equipment'               => __( 'Add Equipment', 'mcp-ai-wpoos-pro' ),
			'track_equipment_maintenance' => __( 'Track Equipment Maintenance', 'mcp-ai-wpoos-pro' ),
			'equipment_inventory_report'  => __( 'Equipment Inventory Report', 'mcp-ai-wpoos-pro' ),
			'create_playlist'             => __( 'Create Playlist', 'mcp-ai-wpoos-pro' ),
			'update_playlist'             => __( 'Update Playlist', 'mcp-ai-wpoos-pro' ),
			'share_playlist_with_client'  => __( 'Share Playlist with Client', 'mcp-ai-wpoos-pro' ),
			'schedule_event'              => __( 'Schedule Event', 'mcp-ai-wpoos-pro' ),
			'update_event_details'        => __( 'Update Event Details', 'mcp-ai-wpoos-pro' ),
			'block_dates'                 => __( 'Block Dates', 'mcp-ai-wpoos-pro' ),
			'add_client'                  => __( 'Add Client', 'mcp-ai-wpoos-pro' ),
			'store_client_preferences'    => __( 'Store Client Preferences', 'mcp-ai-wpoos-pro' ),
			'manage_song_requests'        => __( 'Manage Song Requests', 'mcp-ai-wpoos-pro' ),
			'organize_music_library'      => __( 'Organize Music Library', 'mcp-ai-wpoos-pro' ),
			'search_tracks_by_criteria'   => __( 'Search Tracks by Criteria', 'mcp-ai-wpoos-pro' ),
			'generate_contract'           => __( 'Generate Contract', 'mcp-ai-wpoos-pro' ),
			'track_payments'              => __( 'Track Payments', 'mcp-ai-wpoos-pro' ),
			'generate_invoice'            => __( 'Generate Invoice', 'mcp-ai-wpoos-pro' ),
			'event_performance_report'    => __( 'Event Performance Report', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_DJ_Management_Settings_Page();
}
