<?php
/**
 * Characterization tests for the Wave F2 calendar-booking data layer —
 * the ported appointment/service/staff CPTs, their seven metabox classes,
 * the booking-adapter interface + factory, the orchestration optimizer,
 * and the slimmed standalone init.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical constants, hooks,
 *   and adapter contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/calendar-booking/`, `src/metaboxes/`, `src/adapters/`, and
 *   `src/tools/calendar-booking/` are asserted in full, including the
 *   init wiring and the registry modules.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Calendar-booking data layer tests.
 */
class Test_Calendar_Data_Layer extends WP_UnitTestCase {

	/**
	 * The thirteen ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Appointment_CPT'             => 'calendar-booking/class-wp-mcp-ai-appointment-cpt.php',
			'WP_MCP_AI_Service_CPT'                 => 'calendar-booking/class-wp-mcp-ai-service-cpt.php',
			'WP_MCP_AI_Staff_CPT'                   => 'calendar-booking/class-wp-mcp-ai-staff-cpt.php',
			'WP_MCP_AI_Appointment_Metabox_Base'    => 'metaboxes/class-wp-mcp-ai-appointment-metabox-base.php',
			'WP_MCP_AI_Appointment_Metabox_Details' => 'metaboxes/class-wp-mcp-ai-appointment-metabox-details.php',
			'WP_MCP_AI_Appointment_Metabox_Client'  => 'metaboxes/class-wp-mcp-ai-appointment-metabox-client.php',
			'WP_MCP_AI_Service_Metabox_Base'        => 'metaboxes/class-wp-mcp-ai-service-metabox-base.php',
			'WP_MCP_AI_Service_Metabox_Details'     => 'metaboxes/class-wp-mcp-ai-service-metabox-details.php',
			'WP_MCP_AI_Staff_Metabox_Base'          => 'metaboxes/class-wp-mcp-ai-staff-metabox-base.php',
			'WP_MCP_AI_Staff_Metabox_Details'       => 'metaboxes/class-wp-mcp-ai-staff-metabox-details.php',
			'WP_MCP_AI_Calendar_Orchestration_Optimization' => 'tools/calendar-booking/class-wp-mcp-ai-calendar-orchestration-optimization.php',
			'WP_MCP_AI_Booking_Adapter_Interface'   => 'adapters/interface-wp-mcp-ai-booking-adapter.php',
			'WP_MCP_AI_Booking_Adapter_Factory'     => 'adapters/class-wp-mcp-ai-booking-adapter-factory.php',
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
	 * The three CPT constants must be byte-identical and registerable.
	 */
	public function test_cpt_contracts(): void {
		$this->assertSame( 'mcp_appointment', WP_MCP_AI_Appointment_CPT::POST_TYPE );
		$this->assertSame( 'mcp_service', WP_MCP_AI_Service_CPT::POST_TYPE );
		$this->assertSame( 'mcp_staff', WP_MCP_AI_Staff_CPT::POST_TYPE );
	}

	/**
	 * The adapter factory must keep the byte-identical availability probes
	 * (dormant without JetEngine/JetBooking in the matrices).
	 */
	public function test_adapter_factory_contracts(): void {
		$this->assertFalse( WP_MCP_AI_Booking_Adapter_Factory::has_jetappointment() );
		$this->assertFalse( WP_MCP_AI_Booking_Adapter_Factory::has_jetbooking() );
		$this->assertSame( array(), WP_MCP_AI_Booking_Adapter_Factory::get_all_available() );
		$this->assertIsArray( WP_MCP_AI_Booking_Adapter_Factory::get_statuses() );
	}

	/**
	 * The orchestration optimizer must wire its init hooks.
	 */
	public function test_optimizer_init_hooks(): void {
		WP_MCP_AI_Calendar_Orchestration_Optimization::init();
		$this->assertNotFalse( has_action( 'update_option_wp_mcp_ai_business_hours' ) );
		$this->assertNotFalse( has_filter( 'pre_update_option_wp_mcp_ai_pro_schedules' ) );
	}

	/**
	 * Standalone only: the calendar init must load the CPTs + optimizer and
	 * wire the admin-style enqueue.
	 */
	public function test_init_gate_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base calendar init runs at boot.' );
		}

		$cal_init_first_load = ! function_exists( 'wp_mcp_ai_enqueue_calendar_booking_toolkit_admin_styles' );

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/init.php';

		$this->assertTrue( class_exists( 'WP_MCP_AI_Appointment_CPT' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Calendar_Orchestration_Optimization' ) );

		if ( $cal_init_first_load ) {
			$this->assertNotFalse( has_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_calendar_booking_toolkit_admin_styles' ) );
		}
	}

	/**
	 * Standalone only: the registry must declare the calendar + booking-
	 * adapters modules.
	 */
	public function test_registry_modules_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry owns the modules.' );
		}

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$modules = $registry->get_modules();
		$this->assertArrayHasKey( 'toolkit_calendar_booking', $modules );
		$this->assertSame( 'Calendar Booking Toolkit', $modules['toolkit_calendar_booking']['label'] );
		$this->assertArrayHasKey( 'booking_adapters', $modules );
		$this->assertSame( 'Booking Adapters', $modules['booking_adapters']['label'] );
	}
}
