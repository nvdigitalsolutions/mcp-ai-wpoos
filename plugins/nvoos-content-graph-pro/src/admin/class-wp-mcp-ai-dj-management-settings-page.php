<?php
/**
 * WP_MCP_AI_DJ_Management_Settings_Page (ecosystem port - Wave F2, dj-management admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swap with the `src/` root for the toolkit-settings-base require.
 *
 * DJ Management toolkit settings page.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage DJ_Management
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
 * DJ Management Toolkit Settings Page Class
 */
class WP_MCP_AI_DJ_Management_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'dj_management';
		$this->toolkit_name     = __( 'DJ Management Toolkit', 'nvoos-content-graph-pro' );
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
			<h2><?php esc_html_e( 'DJ Management Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="notice notice-info">
				<p><strong><?php esc_html_e( 'Coming Soon - Phase 2.7', 'nvoos-content-graph-pro' ); ?></strong></p>
				<p><?php esc_html_e( 'This toolkit is planned for implementation in Phase 2.7. Tools and features are subject to change.', 'nvoos-content-graph-pro' ); ?></p>
			</div>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Professional DJ business management toolkit with 15-18 tools for equipment tracking, playlist management, event scheduling, and client management.', 'nvoos-content-graph-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Equipment Inventory: Track gear, maintenance schedules, and replacement needs', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Playlist Builder: Create, organize, and share playlists with clients', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Event Calendar: Schedule gigs, block dates, and manage bookings', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Client Database: Store client preferences, song requests, and contact info', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Music Library: Organize tracks by genre, BPM, energy level, and era', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Contract Management: Generate contracts, track deposits, and manage payments', 'nvoos-content-graph-pro' ); ?></li>
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
			<h2><?php esc_html_e( 'DJ Management Toolkit Configuration', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Configuration options will be available when this toolkit is implemented in Phase 2.7.', 'nvoos-content-graph-pro' ); ?></p>
			</div>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'DJ Business Name', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<input type="text" name="business_name" value="" class="regular-text" disabled />
						<p class="description"><?php esc_html_e( 'Your DJ business name for contracts and invoices', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Event Duration (Hours)', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<input type="number" name="default_event_duration" value="4" min="1" max="24" class="small-text" disabled />
						<p class="description"><?php esc_html_e( 'Default duration for new events', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Music Library Sync', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_music_sync" value="1" disabled />
							<?php esc_html_e( 'Sync with Spotify, Apple Music, or local music library', 'nvoos-content-graph-pro' ); ?>
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
			'add_equipment'               => __( 'Add Equipment', 'nvoos-content-graph-pro' ),
			'track_equipment_maintenance' => __( 'Track Equipment Maintenance', 'nvoos-content-graph-pro' ),
			'equipment_inventory_report'  => __( 'Equipment Inventory Report', 'nvoos-content-graph-pro' ),
			'create_playlist'             => __( 'Create Playlist', 'nvoos-content-graph-pro' ),
			'update_playlist'             => __( 'Update Playlist', 'nvoos-content-graph-pro' ),
			'share_playlist_with_client'  => __( 'Share Playlist with Client', 'nvoos-content-graph-pro' ),
			'schedule_event'              => __( 'Schedule Event', 'nvoos-content-graph-pro' ),
			'update_event_details'        => __( 'Update Event Details', 'nvoos-content-graph-pro' ),
			'block_dates'                 => __( 'Block Dates', 'nvoos-content-graph-pro' ),
			'add_client'                  => __( 'Add Client', 'nvoos-content-graph-pro' ),
			'store_client_preferences'    => __( 'Store Client Preferences', 'nvoos-content-graph-pro' ),
			'manage_song_requests'        => __( 'Manage Song Requests', 'nvoos-content-graph-pro' ),
			'organize_music_library'      => __( 'Organize Music Library', 'nvoos-content-graph-pro' ),
			'search_tracks_by_criteria'   => __( 'Search Tracks by Criteria', 'nvoos-content-graph-pro' ),
			'generate_contract'           => __( 'Generate Contract', 'nvoos-content-graph-pro' ),
			'track_payments'              => __( 'Track Payments', 'nvoos-content-graph-pro' ),
			'generate_invoice'            => __( 'Generate Invoice', 'nvoos-content-graph-pro' ),
			'event_performance_report'    => __( 'Event Performance Report', 'nvoos-content-graph-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_DJ_Management_Settings_Page();
}
