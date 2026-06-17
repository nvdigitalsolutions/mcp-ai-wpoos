<?php
/**
 * Calendar & Booking Toolkit MCP Server
 *
 * Phase 2 Tier-1 promotion. See docs/ADR_002_toolkit_mcp_servers.md.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

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
		return __( 'Calendar & Booking', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Appointment and booking management — availability rules, scheduling, reminders, and Google/Outlook calendar sync.',
			'mcp-ai-wpoos-pro'
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
				'label'              => __( 'Research & Add Appointments', 'mcp-ai-wpoos-pro' ),
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
