<?php
/**
 * Characterization tests for the Wave F2 calendar event tool batch — the
 * ported event CRUD tools, the calendar view tool, the ICS export tool
 * (with its document-response trait + vendor asset), and the standalone
 * wiring.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surfaces and
 *   lifecycle contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/calendar-booking/` are asserted in full, including the
 *   filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Calendar event tool batch tests.
 */
class Test_Calendar_Event_Tools extends WP_UnitTestCase {

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
			'WP_MCP_AI_Tool_Create_Event'        => 'tools/calendar-booking/class-wp-mcp-ai-tool-create-event.php',
			'WP_MCP_AI_Tool_Update_Event'        => 'tools/calendar-booking/class-wp-mcp-ai-tool-update-event.php',
			'WP_MCP_AI_Tool_Delete_Event'        => 'tools/calendar-booking/class-wp-mcp-ai-tool-delete-event.php',
			'WP_MCP_AI_Tool_List_Events'         => 'tools/calendar-booking/class-wp-mcp-ai-tool-list-events.php',
			'WP_MCP_AI_Tool_Get_Calendar_View'   => 'tools/calendar-booking/class-wp-mcp-ai-tool-get-calendar-view.php',
			'WP_MCP_AI_Tool_Export_Calendar_ICS' => 'tools/calendar-booking/class-wp-mcp-ai-tool-export-calendar-ics.php',
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
			'WP_MCP_AI_Tool_Create_Event'        => 'create_event',
			'WP_MCP_AI_Tool_Update_Event'        => 'update_event',
			'WP_MCP_AI_Tool_Delete_Event'        => 'delete_event',
			'WP_MCP_AI_Tool_List_Events'         => 'list_events',
			'WP_MCP_AI_Tool_Get_Calendar_View'   => 'get_calendar_view',
			'WP_MCP_AI_Tool_Export_Calendar_ICS' => 'export_calendar_ics',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The event CRUD lifecycle must create, list, update, and delete.
	 */
	public function test_event_crud_lifecycle(): void {
		WP_MCP_AI_Event_CPT::register_post_type();

		$tool = new WP_MCP_AI_Tool_Create_Event();

		$missing = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_title', $missing->get_error_code() );

		$result = $tool->execute(
			array(
				'title'      => 'Quarterly Review',
				'start_date' => '2026-09-15',
				'end_date'   => '2026-09-15',
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$event_id = $result['event_id'];
		$this->assertSame( 'mcp_ai_event', get_post( $event_id )->post_type );

		$list   = new WP_MCP_AI_Tool_List_Events();
		$listed = $list->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $listed );

		$update  = new WP_MCP_AI_Tool_Update_Event();
		$updated = $update->execute(
			array(
				'event_id' => $event_id,
				'title'    => 'Quarterly Review v2',
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $updated );

		$delete  = new WP_MCP_AI_Tool_Delete_Event();
		$deleted = $delete->execute( array( 'event_id' => $event_id ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $deleted );
		$this->assertNull( get_post( $event_id ) );
	}

	/**
	 * The calendar-view tool must return its read-only envelope.
	 */
	public function test_calendar_view_smoke(): void {
		$view = new WP_MCP_AI_Tool_Get_Calendar_View();

		$missing = $view->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_dates', $missing->get_error_code() );

		$result = $view->execute(
			array(
				'start_date' => '2026-09-01',
				'end_date'   => '2026-09-30',
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $result );
	}

	/**
	 * Standalone only: the init's tool filter must carry the event batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the calendar tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/calendar-booking/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_calendar_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Event', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Export_Calendar_ICS', $tools );
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
		$this->assertNotNull( $parent->all()['create_event'] ?? null );
		$this->assertNotNull( $parent->all()['get_calendar_view'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'list_events' ) );
		$this->assertTrue( $core_tools->has( 'export_calendar_ics' ) );
	}
}
