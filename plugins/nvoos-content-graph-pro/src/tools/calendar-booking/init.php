<?php
/**
 * Calendar Booking Toolkit Initialization (ecosystem port — Wave F2,
 * calendar-booking data layer).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/calendar-booking/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Loads the calendar-booking data layer:
 * the appointment/service/staff CPTs (with their metaboxes) and the
 * orchestration optimizer.
 *
 * Documented deviations from the monolith copy:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`.
 * 3. The booking adapters land via the registry's `booking_adapters`
 *    module (same split as the monolith); the JetEngine/JetBooking
 *    concrete adapters stay conditionally required (dormant standalone —
 *    neither plugin is active in the test matrix).
 * 4. The admin research/settings pages land with the calendar admin
 *    slice (ported; the file-gates below now fire).
 * 5. Monolith guard — this init declares the global helper
 *    `wp_mcp_ai_enqueue_calendar_booking_toolkit_admin_styles()` that the
 *    base calendar init also declares; the collision is a compile-time
 *    fatal, so the ENTIRE body is wrapped in a runtime
 *    `! defined( 'WP_MCP_AI_PATH' )` block (the declarations register only
 *    when the block executes — same pattern as the PM init deviation 6).
 * 6. New standalone-only tool wiring (same pattern as the PM init
 *    deviation 5): a `wp_mcp_ai_pro_tools` filter carrying the ported
 *    calendar tool subset (fills as the tool batches land) plus
 *    `wp_mcp_ai_pro_register_calendar_ecosystem_tools()` registering the
 *    ported tools into the ecosystem graph ToolRegistry. The fifteen
 *    appointment/slot extras (block-time-slot, cancel/create/update/
 *    reschedule-appointment, check-availability, available-slots,
 *    appointment-details, booking-link, reminders/confirmations,
 *    availability-rules, optimize-schedule, google/outlook sync) exist in
 *    the base tree but are NOT part of the monolith's inline calendar tool
 *    map — the filter/ecosystem additions here are the standalone
 *    registrations for those files (CRM CC-extras precedent).
 *
 * @package NvoosContentGraphPro
 * @since   1.0.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Monolith guard (deviation 5): full-body runtime wrap — see the header.
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Load Calendar Booking Custom Post Types (always load for CPT registration).
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/calendar-booking/class-wp-mcp-ai-appointment-cpt.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/calendar-booking/class-wp-mcp-ai-service-cpt.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/calendar-booking/class-wp-mcp-ai-staff-cpt.php';

	// Load Calendar Booking admin pages only when the toolkit is enabled
	// (deferred — file-gated until the calendar admin slice lands).
	if ( is_admin() ) {
		$nvoos_content_graph_pro_cal_settings      = get_option( 'wp_mcp_ai_settings', array() );
		$nvoos_content_graph_pro_cal_is_enabled    = ! empty( $nvoos_content_graph_pro_cal_settings['enable_calendar_booking_toolkit'] );
		$nvoos_content_graph_pro_cal_is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
		$nvoos_content_graph_pro_cal_is_pro_active = defined( 'NVOOS_CONTENT_GRAPH_PRO_VERSION' );

		if ( $nvoos_content_graph_pro_cal_is_enabled && ( ! $nvoos_content_graph_pro_cal_is_base || $nvoos_content_graph_pro_cal_is_pro_active ) ) {
			$nvoos_content_graph_pro_cal_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-calendar-booking-research-page.php';
			if ( file_exists( $nvoos_content_graph_pro_cal_research ) ) {
				require_once $nvoos_content_graph_pro_cal_research;
			}
			$nvoos_content_graph_pro_cal_settings_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-calendar-booking-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_cal_settings_page ) ) {
				require_once $nvoos_content_graph_pro_cal_settings_page;
			}
		}
	}

	// --- Performance optimization (business hours autoload, appointment retention, schedule cap, orphan detection) ---
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-calendar-orchestration-optimization.php';
	WP_MCP_AI_Calendar_Orchestration_Optimization::init();

	/**
	 * Enqueue calendar booking toolkit admin styles.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_calendar_booking_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_calendar_booking_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$nvoos_content_graph_pro_cal_css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-calendar-booking-toolkit.css';
		if ( file_exists( $nvoos_content_graph_pro_cal_css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-calendar-booking-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-calendar-booking-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_calendar_booking_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported calendar tool subset
	 * (inert standalone, consumed by the base plugin monolith). The map
	 * fills as the calendar tool batches land.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_calendar_tools( $tools ) {
		$nvoos_content_graph_pro_cal_tools = array(
			'WP_MCP_AI_Tool_Create_Event'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-create-event.php',
			'WP_MCP_AI_Tool_Update_Event'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-update-event.php',
			'WP_MCP_AI_Tool_Delete_Event'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-delete-event.php',
			'WP_MCP_AI_Tool_List_Events'                  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-list-events.php',
			'WP_MCP_AI_Tool_Get_Calendar_View'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-get-calendar-view.php',
			'WP_MCP_AI_Tool_Export_Calendar_ICS'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-export-calendar-ics.php',
			'WP_MCP_AI_Tool_Create_Service'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-create-service.php',
			'WP_MCP_AI_Tool_Import_Services'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-import-services.php',
			'WP_MCP_AI_Tool_Get_No_Show_Appointments'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-get-no-show-appointments.php',
			'WP_MCP_AI_Tool_Get_Unconfirmed_Bookings'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-get-unconfirmed-bookings.php',
			'WP_MCP_AI_Tool_Send_Booking_Confirmations'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-send-booking-confirmations.php',
			'WP_MCP_AI_Tool_Send_Reschedule_Invitation'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-send-reschedule-invitation.php',
			'WP_MCP_AI_Tool_Sync_From_JetAppointment'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-sync-from-jetappointment.php',
			'WP_MCP_AI_Tool_Sync_To_JetAppointment'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-sync-to-jetappointment.php',
			'WP_MCP_AI_Tool_Sync_From_JetBooking'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-sync-from-jetbooking.php',
			'WP_MCP_AI_Tool_Get_JetAppointment_Providers' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-get-jetappointment-providers.php',
			'WP_MCP_AI_Tool_Get_JetAppointment_Services'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-get-jetappointment-services.php',
			'WP_MCP_AI_Tool_Get_JetBooking_Units'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-get-jetbooking-units.php',
			'WP_MCP_AI_Tool_Get_JetBooking_Instances'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-get-jetbooking-instances.php',
			'WP_MCP_AI_Tool_Block_Time_Slot'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-block-time-slot.php',
			'WP_MCP_AI_Tool_Cancel_Appointment'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-cancel-appointment.php',
			'WP_MCP_AI_Tool_Check_Availability'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-check-availability.php',
			'WP_MCP_AI_Tool_Create_Appointment'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-create-appointment.php',
			'WP_MCP_AI_Tool_Update_Appointment'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-update-appointment.php',
			'WP_MCP_AI_Tool_Get_Appointment_Details'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-get-appointment-details.php',
			'WP_MCP_AI_Tool_Get_Available_Slots'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-get-available-slots.php',
			'WP_MCP_AI_Tool_Generate_Booking_Link'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-generate-booking-link.php',
			'WP_MCP_AI_Tool_Reschedule_Appointment'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-reschedule-appointment.php',
			'WP_MCP_AI_Tool_Send_Appointment_Reminder'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-send-appointment-reminder.php',
			'WP_MCP_AI_Tool_Send_Booking_Confirmation'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-send-booking-confirmation.php',
			'WP_MCP_AI_Tool_Set_Availability_Rules'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-set-availability-rules.php',
			'WP_MCP_AI_Tool_Optimize_Schedule'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-optimize-schedule.php',
			'WP_MCP_AI_Tool_Sync_Google_Calendar'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-sync-google-calendar.php',
			'WP_MCP_AI_Tool_Sync_Outlook_Calendar'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/class-wp-mcp-ai-tool-sync-outlook-calendar.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_cal_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported calendar
	 * tools into the ecosystem graph ToolRegistry and the nvoos/core
	 * registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the
	 * CRM/e-commerce/PM inits). The list fills as the calendar tool batches
	 * land.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_calendar_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Create_Event',
				'WP_MCP_AI_Tool_Update_Event',
				'WP_MCP_AI_Tool_Delete_Event',
				'WP_MCP_AI_Tool_List_Events',
				'WP_MCP_AI_Tool_Get_Calendar_View',
				'WP_MCP_AI_Tool_Export_Calendar_ICS',
				'WP_MCP_AI_Tool_Create_Service',
				'WP_MCP_AI_Tool_Import_Services',
				'WP_MCP_AI_Tool_Get_No_Show_Appointments',
				'WP_MCP_AI_Tool_Get_Unconfirmed_Bookings',
				'WP_MCP_AI_Tool_Send_Booking_Confirmations',
				'WP_MCP_AI_Tool_Send_Reschedule_Invitation',
				'WP_MCP_AI_Tool_Sync_From_JetAppointment',
				'WP_MCP_AI_Tool_Sync_To_JetAppointment',
				'WP_MCP_AI_Tool_Sync_From_JetBooking',
				'WP_MCP_AI_Tool_Get_JetAppointment_Providers',
				'WP_MCP_AI_Tool_Get_JetAppointment_Services',
				'WP_MCP_AI_Tool_Get_JetBooking_Units',
				'WP_MCP_AI_Tool_Get_JetBooking_Instances',
				'WP_MCP_AI_Tool_Block_Time_Slot',
				'WP_MCP_AI_Tool_Cancel_Appointment',
				'WP_MCP_AI_Tool_Check_Availability',
				'WP_MCP_AI_Tool_Create_Appointment',
				'WP_MCP_AI_Tool_Update_Appointment',
				'WP_MCP_AI_Tool_Get_Appointment_Details',
				'WP_MCP_AI_Tool_Get_Available_Slots',
				'WP_MCP_AI_Tool_Generate_Booking_Link',
				'WP_MCP_AI_Tool_Reschedule_Appointment',
				'WP_MCP_AI_Tool_Send_Appointment_Reminder',
				'WP_MCP_AI_Tool_Send_Booking_Confirmation',
				'WP_MCP_AI_Tool_Set_Availability_Rules',
				'WP_MCP_AI_Tool_Optimize_Schedule',
				'WP_MCP_AI_Tool_Sync_Google_Calendar',
				'WP_MCP_AI_Tool_Sync_Outlook_Calendar',
			) as $nvoos_content_graph_pro_tool_class
		) {
			$nvoos_content_graph_pro_adapter = new WP_MCP_AI_Pro_Tool_Adapter( new $nvoos_content_graph_pro_tool_class() );
			try {
				$nvoos_content_graph_pro_parent_registry->register( $nvoos_content_graph_pro_adapter );
			} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
				unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
			}

			// Wrap into the nvoos/core registry so the agentic chat loop can
			// resolve and execute the tool (same path the AI addon uses).
			if ( class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
				$nvoos_content_graph_pro_core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
				try {
					$nvoos_content_graph_pro_core_tools->register( new \NvoosContentGraphAi\Adapter\GraphToolAdapter( $nvoos_content_graph_pro_adapter ) );
				} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
					unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
				}
			}
		}
	}

	// ---- Standalone-only tool wiring (deviation 6). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_calendar_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_calendar_ecosystem_tools();
	}
} // End monolith guard (deviation 5).
