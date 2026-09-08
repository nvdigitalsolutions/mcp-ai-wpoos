<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-calendar-booking-mcp-server.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
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

/**
 * Calendar & Booking MCP server.
 */
class WP_MCP_AI_Calendar_Booking_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'calendar-booking';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Calendar & Booking', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Appointment and booking management — availability rules, scheduling, reminders, and Google/Outlook calendar sync.',
			'nvoos-content-graph-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array(
			array(
				'type'               => 'research_add',
				'page_slug'          => 'research-appointment',
				'entity_type'        => 'mcp_appointment',
				'class_ref'          => 'WP_MCP_AI_Calendar_Booking_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Appointments', 'nvoos-content-graph-pro' ),
			),
		);
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the Calendar & Booking MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_calendar_booking_candidate_tools',
			array(
				'create_appointment',
				'update_appointment',
				'cancel_appointment',
				'reschedule_appointment',
				'check_availability',
				'get_available_slots',
				'set_availability_rules',
				'block_time_slot',
				'get_appointment_details',
				'get_calendar_view',
				'generate_booking_link',
				'send_appointment_reminder',
				'send_booking_confirmation',
				'sync_google_calendar',
				'sync_outlook_calendar',
				'optimize_schedule',
				'export_calendar_ics',
			)
		);
	}
}
