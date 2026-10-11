<?php
/**
 * Tests for WP_MCP_AI_Read_Only_Profile_Gate — the pre-execution enforcement
 * hook that blocks write/state-changing/destructive tools under a restrictive
 * chat profile (proposal 015, read-only mode).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test file contains a stub tool class by design.
// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test file contains a stub tool class by design.

/**
 * Test tool implementing the two contracts the gate inspects.
 *
 * Defined in the test file so the capability flags are freely settable per
 * test without touching the live registry.
 */
class Test_Chat_Profile_Gate_Stub_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * Tool slug.
	 *
	 * @var string
	 */
	public $slug = 'gate_test_tool';

	/**
	 * Tool display name.
	 *
	 * @var string
	 */
	public $name = 'Gate Test Tool';

	/**
	 * Capability flags reported by the tool.
	 *
	 * @var array
	 */
	public $flags = array( 'write' );

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Test tool for the chat-profile gate suite.';
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array();
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		unset( $arguments, $context );
		return array( 'success' => true );
	}

	/**
	 * Get the capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return $this->flags;
	}
}

/**
 * Read-only chat profile gate test suite.
 *
 * @group security
 * @group chat-profile
 */
class Test_Chat_Profile_Gate extends WP_UnitTestCase {

	/**
	 * Set up: default settings, fresh caches, anonymous current user.
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 0 );
		WP_MCP_AI_Chat_Profile_Manager::reset_cache();
		WP_MCP_AI_Chat_Profile_Registry::reset_cache();
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
	}

	/**
	 * Tear down: restore pristine settings and caches.
	 */
	public function tearDown(): void {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings_Base::get_default_settings() );
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		WP_MCP_AI_Chat_Profile_Manager::reset_cache();
		WP_MCP_AI_Chat_Profile_Registry::reset_cache();
		remove_all_filters( 'wp_mcp_ai_security_event' );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Build a tool with the given flags.
	 *
	 * @param string $slug  Tool slug.
	 * @param array  $flags Capability flags.
	 * @return Test_Chat_Profile_Gate_Stub_Tool
	 */
	private function make_tool( $slug = 'gate_test_tool', array $flags = array( 'write' ) ) {
		$tool        = new Test_Chat_Profile_Gate_Stub_Tool();
		$tool->slug  = $slug;
		$tool->flags = $flags;
		return $tool;
	}

	// -------------------------------------------------------------------------
	// is_tool_allowed().
	// -------------------------------------------------------------------------

	/**
	 * A write-flag tool is disallowed under read-only.
	 */
	public function test_is_tool_allowed_blocks_write_flag() {
		$profile = WP_MCP_AI_Chat_Profile_Registry::get_profile( WP_MCP_AI_Chat_Profile::PROFILE_READ_ONLY );
		$tool    = $this->make_tool( 'write_tool', array( 'write' ) );

		$this->assertFalse( WP_MCP_AI_Read_Only_Profile_Gate::is_tool_allowed( $tool, $profile ) );
	}

	/**
	 * Read-only tools pass under read-only; everything passes under write.
	 */
	public function test_is_tool_allowed_read_only_passes() {
		$read_only = WP_MCP_AI_Chat_Profile_Registry::get_profile( WP_MCP_AI_Chat_Profile::PROFILE_READ_ONLY );
		$write     = WP_MCP_AI_Chat_Profile_Registry::get_profile( WP_MCP_AI_Chat_Profile::PROFILE_WRITE );

		$read_tool  = $this->make_tool( 'read_tool', array( 'read-only', 'cacheable' ) );
		$write_tool = $this->make_tool( 'write_tool', array( 'write', 'state-changing' ) );

		$this->assertTrue( WP_MCP_AI_Read_Only_Profile_Gate::is_tool_allowed( $read_tool, $read_only ) );
		$this->assertTrue( WP_MCP_AI_Read_Only_Profile_Gate::is_tool_allowed( $write_tool, $write ) );
	}

	/**
	 * A tool without the flags interface carries no flags and passes.
	 */
	public function test_is_tool_allowed_without_flags_interface() {
		$profile = WP_MCP_AI_Chat_Profile_Registry::get_profile( WP_MCP_AI_Chat_Profile::PROFILE_READ_ONLY );

		// A mock of the bare tool interface does not implement the flags
		// contract — the gate must treat it as unflagged, never fatal.
		$tool = $this->getMockBuilder( 'WP_MCP_AI_Tool_Interface' )
			->disableOriginalConstructor()
			->getMock();
		$tool->method( 'get_slug' )->willReturn( 'plain_tool' );

		$this->assertTrue( WP_MCP_AI_Read_Only_Profile_Gate::is_tool_allowed( $tool, $profile ) );
	}

	// -------------------------------------------------------------------------
	// on_before_tool_execution() — the enforcement hook.
	// -------------------------------------------------------------------------

	/**
	 * A write-flag tool under a read-only context is rejected with the
	 * canonical exception carrying a 403 WP_Error envelope.
	 */
	public function test_gate_blocks_write_tool_under_read_only() {
		$tool = $this->make_tool( 'edit_post', array( 'write', 'state-changing' ) );

		$caught = null;
		try {
			WP_MCP_AI_Read_Only_Profile_Gate::on_before_tool_execution(
				'edit_post',
				array(),
				array( 'chat_profile' => 'read-only' ),
				$tool
			);
		} catch ( WP_MCP_AI_Chat_Profile_Blocked $e ) {
			$caught = $e;
		}

		$this->assertInstanceOf( 'WP_MCP_AI_Chat_Profile_Blocked', $caught );
		$this->assertSame( 'edit_post', $caught->get_tool_slug() );

		$error = $caught->to_wp_error();
		$this->assertSame( 'wp_mcp_ai_chat_profile_blocked', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertSame( 'read-only', $error->get_error_data()['profile'] );
	}

	/**
	 * A read-only tool under a read-only context executes untouched.
	 */
	public function test_gate_allows_read_tool_under_read_only() {
		$tool = $this->make_tool( 'read_post', array( 'read-only' ) );

		WP_MCP_AI_Read_Only_Profile_Gate::on_before_tool_execution(
			'read_post',
			array(),
			array( 'chat_profile' => 'read-only' ),
			$tool
		);

		$this->assertTrue( true ); // No exception thrown = allowed.
	}

	/**
	 * A write-flag tool under a write context executes untouched.
	 */
	public function test_gate_allows_write_tool_under_write() {
		$tool = $this->make_tool( 'edit_post', array( 'write' ) );

		WP_MCP_AI_Read_Only_Profile_Gate::on_before_tool_execution(
			'edit_post',
			array(),
			array( 'chat_profile' => 'write' ),
			$tool
		);

		$this->assertTrue( true ); // No exception thrown = allowed.
	}

	/**
	 * When the feature is disabled the gate is inert.
	 */
	public function test_gate_inert_when_feature_disabled() {
		$settings                         = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$settings['chat_profile_enabled'] = false;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		WP_MCP_AI_Chat_Profile_Manager::reset_cache();

		$tool = $this->make_tool( 'edit_post', array( 'write', 'destructive' ) );

		WP_MCP_AI_Read_Only_Profile_Gate::on_before_tool_execution(
			'edit_post',
			array(),
			array( 'chat_profile' => 'read-only' ),
			$tool
		);

		$this->assertTrue( true ); // No exception thrown = feature disabled.
	}

	/**
	 * Without a chat_profile context entry the gate resolves from the current
	 * user — an anonymous guest resolves to the read-only guest profile and
	 * is blocked from writing.
	 */
	public function test_gate_falls_back_to_guest_resolution() {
		wp_set_current_user( 0 );

		$tool = $this->make_tool( 'edit_post', array( 'write' ) );

		$this->expectException( 'WP_MCP_AI_Chat_Profile_Blocked' );
		WP_MCP_AI_Read_Only_Profile_Gate::on_before_tool_execution( 'edit_post', array(), array(), $tool );
	}

	/**
	 * A blocked execution is audited with the chat-profile-blocked event.
	 */
	public function test_gate_logs_audit_event_on_block() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$events = array();
		add_action(
			'wp_mcp_ai_security_event',
			function ( $event_type, $event_user_id, $ip_address, $details ) use ( &$events ) {
				unset( $ip_address );
				$events[] = array(
					'type'    => $event_type,
					'user_id' => $event_user_id,
					'details' => $details,
				);
			},
			10,
			4
		);

		$tool = $this->make_tool( 'edit_post', array( 'write' ) );

		try {
			WP_MCP_AI_Read_Only_Profile_Gate::on_before_tool_execution(
				'edit_post',
				array(),
				array( 'chat_profile' => 'read-only' ),
				$tool
			);
			$this->fail( 'Expected the gate to throw.' );
		} catch ( WP_MCP_AI_Chat_Profile_Blocked $e ) {
			unset( $e );
		}

		$blocked = array_values(
			array_filter(
				$events,
				function ( $event ) {
					return WP_MCP_AI_Security_Audit_Logger::EVENT_CHAT_PROFILE_BLOCKED === $event['type'];
				}
			)
		);

		$this->assertNotEmpty( $blocked, 'Blocked execution should be audited.' );
		$this->assertSame( $user_id, $blocked[0]['user_id'] );
		$this->assertSame( 'edit_post', $blocked[0]['details']['tool_slug'] );
		$this->assertSame( 'read-only', $blocked[0]['details']['profile'] );

		remove_all_filters( 'wp_mcp_ai_security_event' );
	}

	/**
	 * The rejection fires the observer action with the payload.
	 */
	public function test_gate_fires_rejected_observer_action() {
		$observed = null;
		add_action(
			'wp_mcp_ai_chat_profile_gate_rejected',
			function ( $tool_slug, $payload ) use ( &$observed ) {
				$observed = array( $tool_slug, $payload );
			},
			10,
			2
		);

		$tool = $this->make_tool( 'edit_post', array( 'write' ) );

		try {
			WP_MCP_AI_Read_Only_Profile_Gate::on_before_tool_execution(
				'edit_post',
				array(),
				array( 'chat_profile' => 'read-only' ),
				$tool
			);
			$this->fail( 'Expected the gate to throw.' );
		} catch ( WP_MCP_AI_Chat_Profile_Blocked $e ) {
			unset( $e );
		}

		$this->assertNotNull( $observed, 'Observer action should fire on rejection.' );
		$this->assertSame( 'edit_post', $observed[0] );
		$this->assertSame( 'read-only', $observed[1]['profile'] );

		remove_all_filters( 'wp_mcp_ai_chat_profile_gate_rejected' );
	}

	// -------------------------------------------------------------------------
	// Hook precedence (deny → ask → allow).
	// -------------------------------------------------------------------------

	/**
	 * Both gates are registered on the before-execution hook at priority 0,
	 * and the read-only profile gate runs BEFORE the destructive-ops gate so
	 * a denied write never degrades into a confirmation prompt.
	 */
	public function test_profile_gate_precedes_destructive_gate() {
		global $wp_filter;

		$this->assertArrayHasKey( 'wp_mcp_ai_before_tool_execution', $wp_filter );

		$hook     = $wp_filter['wp_mcp_ai_before_tool_execution'];
		$priority = 0;

		$this->assertSame( 0, $hook->has_filter( 'wp_mcp_ai_before_tool_execution', array( 'WP_MCP_AI_Read_Only_Profile_Gate', 'on_before_tool_execution' ) ) );
		$this->assertSame( 0, $hook->has_filter( 'wp_mcp_ai_before_tool_execution', array( 'WP_MCP_AI_Destructive_Ops_Gate', 'on_before_tool_execution' ) ) );

		$callbacks         = $hook->callbacks[ $priority ];
		$positions         = array_keys( $callbacks );
		$profile_index     = array_search( 'WP_MCP_AI_Read_Only_Profile_Gate::on_before_tool_execution', $positions, true );
		$destructive_index = array_search( 'WP_MCP_AI_Destructive_Ops_Gate::on_before_tool_execution', $positions, true );

		$this->assertNotFalse( $profile_index, 'Profile gate callback should be hooked.' );
		$this->assertNotFalse( $destructive_index, 'Destructive gate callback should be hooked.' );
		$this->assertLessThan(
			$destructive_index,
			$profile_index,
			'The profile gate must run before the destructive-ops gate.'
		);
	}
}
