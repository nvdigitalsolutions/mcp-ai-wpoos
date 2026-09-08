<?php
/**
 * Characterization tests for the Wave F2 calendar services + booking-ops
 * batch — the ported service CRUD/import tools, the no-show/unconfirmed
 * query tools, and the booking-confirmation/reschedule tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surfaces and
 *   contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/calendar-booking/` are asserted in full, including the
 *   filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Calendar services + booking-ops tool batch tests.
 */
class Test_Calendar_Services_Tools extends WP_UnitTestCase {

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
	 * The six ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_Service'             => 'tools/calendar-booking/class-wp-mcp-ai-tool-create-service.php',
			'WP_MCP_AI_Tool_Import_Services'            => 'tools/calendar-booking/class-wp-mcp-ai-tool-import-services.php',
			'WP_MCP_AI_Tool_Get_No_Show_Appointments'   => 'tools/calendar-booking/class-wp-mcp-ai-tool-get-no-show-appointments.php',
			'WP_MCP_AI_Tool_Get_Unconfirmed_Bookings'   => 'tools/calendar-booking/class-wp-mcp-ai-tool-get-unconfirmed-bookings.php',
			'WP_MCP_AI_Tool_Send_Booking_Confirmations' => 'tools/calendar-booking/class-wp-mcp-ai-tool-send-booking-confirmations.php',
			'WP_MCP_AI_Tool_Send_Reschedule_Invitation' => 'tools/calendar-booking/class-wp-mcp-ai-tool-send-reschedule-invitation.php',
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
	 * The six tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_Service'             => 'create_service',
			'WP_MCP_AI_Tool_Import_Services'            => 'import_services',
			'WP_MCP_AI_Tool_Get_No_Show_Appointments'   => 'get_no_show_appointments',
			'WP_MCP_AI_Tool_Get_Unconfirmed_Bookings'   => 'get_unconfirmed_bookings',
			'WP_MCP_AI_Tool_Send_Booking_Confirmations' => 'send_booking_confirmations',
			'WP_MCP_AI_Tool_Send_Reschedule_Invitation' => 'send_reschedule_invitation',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$expected_cap = in_array( $class, array( 'WP_MCP_AI_Tool_Get_No_Show_Appointments', 'WP_MCP_AI_Tool_Get_Unconfirmed_Bookings' ), true ) ? 'read' : 'edit_posts';
			$this->assertSame( $expected_cap, $tool->get_required_capability(), $class );
		}
	}

	/**
	 * create-service must enforce the name gate and create the service post.
	 */
	public function test_create_service_execute(): void {
		WP_MCP_AI_Service_CPT::register_post_type();

		$tool = new WP_MCP_AI_Tool_Create_Service();

		$missing = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_name', $missing->get_error_code() );

		$result = $tool->execute(
			array(
				'name'     => 'Consultation',
				'duration' => 30,
				'price'    => 120.00,
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'mcp_service', get_post( $result['service_id'] )->post_type );
	}

	/**
	 * The query tools must return their read-only envelopes on empty data.
	 */
	public function test_query_tools_smoke(): void {
		$no_show = new WP_MCP_AI_Tool_Get_No_Show_Appointments();
		$result  = $no_show->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );

		$unconfirmed = new WP_MCP_AI_Tool_Get_Unconfirmed_Bookings();
		$result      = $unconfirmed->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );
	}

	/**
	 * The send tools must keep their booking-id gate.
	 */
	public function test_send_tools_gates(): void {
		$confirmations = new WP_MCP_AI_Tool_Send_Booking_Confirmations();
		$missing       = $confirmations->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'missing_booking_ids', $missing->get_error_code() );
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
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Service', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Send_Reschedule_Invitation', $tools );
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
		$this->assertNotNull( $parent->all()['create_service'] ?? null );
		$this->assertNotNull( $parent->all()['get_no_show_appointments'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'import_services' ) );
		$this->assertTrue( $core_tools->has( 'send_booking_confirmations' ) );
	}
}
