<?php
/**
 * Characterization tests for the Wave F2 calendar appointment/slot extras
 * batch — the fifteen tree-only tools (present in the base tree, absent from
 * the monolith's inline calendar tool map) ported with standalone-only
 * registrations (CRM CC-extras precedent).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the tool
 *   classes (classmap-autoloaded); the byte-identical surfaces and
 *   fail-closed gates are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/calendar-booking/` are asserted in full, including the
 *   filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Calendar extras batch tests.
 */
class Test_Calendar_Extras_Tools extends WP_UnitTestCase {

	/**
	 * Enable the calendar toolkit for availability gates.
	 */
	public function setUp(): void {
		parent::setUp();
		$settings                                    = get_option( 'wp_mcp_ai_settings', array() );
		$settings                                    = is_array( $settings ) ? $settings : array();
		$settings['enable_calendar_booking_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The fifteen ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Block_Time_Slot'           => 'tools/calendar-booking/class-wp-mcp-ai-tool-block-time-slot.php',
			'WP_MCP_AI_Tool_Cancel_Appointment'        => 'tools/calendar-booking/class-wp-mcp-ai-tool-cancel-appointment.php',
			'WP_MCP_AI_Tool_Check_Availability'        => 'tools/calendar-booking/class-wp-mcp-ai-tool-check-availability.php',
			'WP_MCP_AI_Tool_Create_Appointment'        => 'tools/calendar-booking/class-wp-mcp-ai-tool-create-appointment.php',
			'WP_MCP_AI_Tool_Update_Appointment'        => 'tools/calendar-booking/class-wp-mcp-ai-tool-update-appointment.php',
			'WP_MCP_AI_Tool_Get_Appointment_Details'   => 'tools/calendar-booking/class-wp-mcp-ai-tool-get-appointment-details.php',
			'WP_MCP_AI_Tool_Get_Available_Slots'       => 'tools/calendar-booking/class-wp-mcp-ai-tool-get-available-slots.php',
			'WP_MCP_AI_Tool_Generate_Booking_Link'     => 'tools/calendar-booking/class-wp-mcp-ai-tool-generate-booking-link.php',
			'WP_MCP_AI_Tool_Reschedule_Appointment'    => 'tools/calendar-booking/class-wp-mcp-ai-tool-reschedule-appointment.php',
			'WP_MCP_AI_Tool_Send_Appointment_Reminder' => 'tools/calendar-booking/class-wp-mcp-ai-tool-send-appointment-reminder.php',
			'WP_MCP_AI_Tool_Send_Booking_Confirmation' => 'tools/calendar-booking/class-wp-mcp-ai-tool-send-booking-confirmation.php',
			'WP_MCP_AI_Tool_Set_Availability_Rules'    => 'tools/calendar-booking/class-wp-mcp-ai-tool-set-availability-rules.php',
			'WP_MCP_AI_Tool_Optimize_Schedule'         => 'tools/calendar-booking/class-wp-mcp-ai-tool-optimize-schedule.php',
			'WP_MCP_AI_Tool_Sync_Google_Calendar'      => 'tools/calendar-booking/class-wp-mcp-ai-tool-sync-google-calendar.php',
			'WP_MCP_AI_Tool_Sync_Outlook_Calendar'     => 'tools/calendar-booking/class-wp-mcp-ai-tool-sync-outlook-calendar.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The fifteen tool surfaces must be byte-identical (all edit_posts).
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Block_Time_Slot'           => 'block_time_slot',
			'WP_MCP_AI_Tool_Cancel_Appointment'        => 'cancel_appointment',
			'WP_MCP_AI_Tool_Check_Availability'        => 'check_availability',
			'WP_MCP_AI_Tool_Create_Appointment'        => 'create_appointment',
			'WP_MCP_AI_Tool_Update_Appointment'        => 'update_appointment',
			'WP_MCP_AI_Tool_Get_Appointment_Details'   => 'get_appointment_details',
			'WP_MCP_AI_Tool_Get_Available_Slots'       => 'get_available_slots',
			'WP_MCP_AI_Tool_Generate_Booking_Link'     => 'generate_booking_link',
			'WP_MCP_AI_Tool_Reschedule_Appointment'    => 'reschedule_appointment',
			'WP_MCP_AI_Tool_Send_Appointment_Reminder' => 'send_appointment_reminder',
			'WP_MCP_AI_Tool_Send_Booking_Confirmation' => 'send_booking_confirmation',
			'WP_MCP_AI_Tool_Set_Availability_Rules'    => 'set_availability_rules',
			'WP_MCP_AI_Tool_Optimize_Schedule'         => 'optimize_schedule',
			'WP_MCP_AI_Tool_Sync_Google_Calendar'      => 'sync_google_calendar',
			'WP_MCP_AI_Tool_Sync_Outlook_Calendar'     => 'sync_outlook_calendar',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The manage_options-gated tools must fail closed without an admin user.
	 */
	public function test_permission_gates(): void {
		$gated = array(
			'WP_MCP_AI_Tool_Block_Time_Slot',
			'WP_MCP_AI_Tool_Cancel_Appointment',
			'WP_MCP_AI_Tool_Create_Appointment',
			'WP_MCP_AI_Tool_Update_Appointment',
			'WP_MCP_AI_Tool_Get_Appointment_Details',
			'WP_MCP_AI_Tool_Generate_Booking_Link',
			'WP_MCP_AI_Tool_Reschedule_Appointment',
			'WP_MCP_AI_Tool_Send_Appointment_Reminder',
			'WP_MCP_AI_Tool_Send_Booking_Confirmation',
			'WP_MCP_AI_Tool_Set_Availability_Rules',
			'WP_MCP_AI_Tool_Optimize_Schedule',
			'WP_MCP_AI_Tool_Sync_Google_Calendar',
			'WP_MCP_AI_Tool_Sync_Outlook_Calendar',
		);

		foreach ( $gated as $class ) {
			$tool   = new $class();
			$result = $tool->execute( array(), array() );
			$this->assertWPError( $result, $class );
			$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code(), $class );
		}
	}

	/**
	 * check-availability must keep its time gates.
	 */
	public function test_check_availability_gates(): void {
		$tool = new WP_MCP_AI_Tool_Check_Availability();

		$missing = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'missing_time', $missing->get_error_code() );

		$invalid = $tool->execute(
			array(
				'start_time' => 'not-a-date',
				'end_time'   => 'also-not-a-date',
			),
			array( 'user_id' => 1 )
		);
		$this->assertWPError( $invalid );
		$this->assertSame( 'invalid_time_format', $invalid->get_error_code() );
	}

	/**
	 * get-available-slots must reject an invalid date.
	 */
	public function test_get_available_slots_gate(): void {
		$tool   = new WP_MCP_AI_Tool_Get_Available_Slots();
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_date', $result->get_error_code() );
	}

	/**
	 * As an admin, the ID/range-gated tools must reach their argument gates.
	 */
	public function test_admin_path_gates(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$args  = array( 'user_id' => $admin );

		$missing_appointment_id = array(
			'WP_MCP_AI_Tool_Cancel_Appointment',
			'WP_MCP_AI_Tool_Update_Appointment',
			'WP_MCP_AI_Tool_Get_Appointment_Details',
			'WP_MCP_AI_Tool_Reschedule_Appointment',
		);
		foreach ( $missing_appointment_id as $class ) {
			$tool   = new $class();
			$result = $tool->execute( array(), $args );
			$this->assertWPError( $result, $class );
			$this->assertSame( 'missing_appointment_id', $result->get_error_code(), $class );
		}

		$missing_id = array(
			'WP_MCP_AI_Tool_Send_Appointment_Reminder',
			'WP_MCP_AI_Tool_Send_Booking_Confirmation',
			'WP_MCP_AI_Tool_Sync_Google_Calendar',
			'WP_MCP_AI_Tool_Sync_Outlook_Calendar',
		);
		foreach ( $missing_id as $class ) {
			$tool   = new $class();
			$result = $tool->execute( array(), $args );
			$this->assertWPError( $result, $class );
			$this->assertSame( 'missing_id', $result->get_error_code(), $class );
		}

		$block_slot = new WP_MCP_AI_Tool_Block_Time_Slot();
		$this->assertSame( 'missing_time', $block_slot->execute( array(), $args )->get_error_code() );

		$rules = new WP_MCP_AI_Tool_Set_Availability_Rules();
		$this->assertSame( 'missing_day', $rules->execute( array(), $args )->get_error_code() );

		$optimize = new WP_MCP_AI_Tool_Optimize_Schedule();
		$this->assertSame( 'missing_dates', $optimize->execute( array(), $args )->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the calendar tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_calendar_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Block_Time_Slot', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Sync_Outlook_Calendar', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the batch
	 * into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/init.php';
		wp_mcp_ai_pro_register_calendar_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['check_availability'] ?? null );
		$this->assertNotNull( $parent->all()['optimize_schedule'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_appointment' ) );
		$this->assertTrue( $core_tools->has( 'sync_google_calendar' ) );
	}
}
