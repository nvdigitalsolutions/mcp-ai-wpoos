<?php
/**
 * Characterization tests for the Wave F2 calendar jet-sync batch — the
 * ported JetAppointment/JetBooking sync + query tools and the concrete
 * booking adapters they gate on.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool + adapter classes (classmap-autoloaded); the byte-identical
 *   surfaces and contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/calendar-booking/` and `src/adapters/` are asserted in
 *   full, including the filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Calendar jet-sync batch tests.
 */
class Test_Calendar_Jet_Sync_Tools extends WP_UnitTestCase {

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
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Sync_From_JetAppointment'     => 'tools/calendar-booking/class-wp-mcp-ai-tool-sync-from-jetappointment.php',
			'WP_MCP_AI_Tool_Sync_To_JetAppointment'       => 'tools/calendar-booking/class-wp-mcp-ai-tool-sync-to-jetappointment.php',
			'WP_MCP_AI_Tool_Sync_From_JetBooking'         => 'tools/calendar-booking/class-wp-mcp-ai-tool-sync-from-jetbooking.php',
			'WP_MCP_AI_Tool_Get_JetAppointment_Providers' => 'tools/calendar-booking/class-wp-mcp-ai-tool-get-jetappointment-providers.php',
			'WP_MCP_AI_Tool_Get_JetAppointment_Services'  => 'tools/calendar-booking/class-wp-mcp-ai-tool-get-jetappointment-services.php',
			'WP_MCP_AI_Tool_Get_JetBooking_Units'         => 'tools/calendar-booking/class-wp-mcp-ai-tool-get-jetbooking-units.php',
			'WP_MCP_AI_Tool_Get_JetBooking_Instances'     => 'tools/calendar-booking/class-wp-mcp-ai-tool-get-jetbooking-instances.php',
			'WP_MCP_AI_JetAppointment_Adapter'            => 'adapters/class-wp-mcp-ai-jetappointment-adapter.php',
			'WP_MCP_AI_JetBooking_Adapter'                => 'adapters/class-wp-mcp-ai-jetbooking-adapter.php',
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
	 * The seven tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Sync_From_JetAppointment'     => 'sync_from_jetappointment',
			'WP_MCP_AI_Tool_Sync_To_JetAppointment'       => 'sync_to_jetappointment',
			'WP_MCP_AI_Tool_Sync_From_JetBooking'         => 'sync_from_jetbooking',
			'WP_MCP_AI_Tool_Get_JetAppointment_Providers' => 'get_jetappointment_providers',
			'WP_MCP_AI_Tool_Get_JetAppointment_Services'  => 'get_jetappointment_services',
			'WP_MCP_AI_Tool_Get_JetBooking_Units'         => 'get_jetbooking_units',
			'WP_MCP_AI_Tool_Get_JetBooking_Instances'     => 'get_jetbooking_instances',
		);

		$read_only = array(
			'WP_MCP_AI_Tool_Get_JetAppointment_Providers',
			'WP_MCP_AI_Tool_Get_JetAppointment_Services',
			'WP_MCP_AI_Tool_Get_JetBooking_Units',
			'WP_MCP_AI_Tool_Get_JetBooking_Instances',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$expected_cap = in_array( $class, $read_only, true ) ? 'read' : 'edit_posts';
			$this->assertSame( $expected_cap, $tool->get_required_capability(), $class );
		}
	}

	/**
	 * Without JetAppointment/JetBooking active, all seven tools must fail
	 * closed with their adapter-unavailable envelopes.
	 */
	public function test_jet_unavailable_gates(): void {
		$expectations = array(
			'WP_MCP_AI_Tool_Sync_From_JetAppointment'     => 'jetappointment_unavailable',
			'WP_MCP_AI_Tool_Sync_To_JetAppointment'       => 'jetappointment_unavailable',
			'WP_MCP_AI_Tool_Sync_From_JetBooking'         => 'jetbooking_unavailable',
			'WP_MCP_AI_Tool_Get_JetAppointment_Providers' => 'jetappointment_unavailable',
			'WP_MCP_AI_Tool_Get_JetAppointment_Services'  => 'jetappointment_unavailable',
			'WP_MCP_AI_Tool_Get_JetBooking_Units'         => 'jetbooking_unavailable',
			'WP_MCP_AI_Tool_Get_JetBooking_Instances'     => 'jetbooking_unavailable',
		);

		foreach ( $expectations as $class => $error_code ) {
			$tool   = new $class();
			$result = $tool->execute( array(), array( 'user_id' => 1 ) );
			$this->assertWPError( $result, $class );
			$this->assertSame( $error_code, $result->get_error_code(), $class );
		}
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
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Sync_From_JetAppointment', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_JetBooking_Instances', $tools );
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
		$this->assertNotNull( $parent->all()['sync_from_jetappointment'] ?? null );
		$this->assertNotNull( $parent->all()['get_jetbooking_units'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'sync_to_jetappointment' ) );
		$this->assertTrue( $core_tools->has( 'get_jetappointment_services' ) );
	}
}
