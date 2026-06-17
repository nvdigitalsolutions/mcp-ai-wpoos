<?php
/**
 * Tests for WP_MCP_AI_Pro_Slash_Command_Mcp_Server.
 *
 * Covers guest blocks, capability gates, all five sub-actions
 * (list / show / enable / disable / tools), and the --json flag.
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';
require_once dirname( __DIR__ ) . '/includes/slash-commands/commands/class-wp-mcp-ai-pro-slash-command-mcp-server.php';

/** Summary.
 *
 * @group toolkit-mcp-servers
 */
class Test_Pro_Slash_Command_Mcp_Server extends WP_UnitTestCase {

	/** Summary.
	 *
	 * @var WP_MCP_AI_Pro_Slash_Command_Mcp_Server
	 */
	private $command;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/** Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Make sure the registry is primed even when running outside the init hook.
		WP_MCP_AI_Toolkit_Server_Registry::get_instance()->bootstrap();

		$this->command   = new WP_MCP_AI_Pro_Slash_Command_Mcp_Server();
		$this->admin_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
	}

	/** Tear down test.
	 */
	public function tearDown(): void {
		// Reset the toggle we may have applied so other tests aren't affected.
		delete_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'crm' );
		wp_delete_user( $this->admin_id );
		wp_delete_user( $this->editor_id );
		parent::tearDown();
	}

	/** Test guest block.
	 */
	public function test_guest_block(): void {
		$result = $this->command->execute( array(), array(), array( 'guest_request' => true ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'guest_forbidden', $result->get_error_code() );
	}

	/** Test capability gate blocks subscribers.
	 */
	public function test_capability_gate_blocks_subscribers(): void {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$result = $this->command->execute( array(), array(), array( 'user_id' => $subscriber_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'forbidden', $result->get_error_code() );

		wp_delete_user( $subscriber_id );
	}

	/** Test editor can list but cannot enable disable.
	 */
	public function test_editor_can_list_but_cannot_enable_disable(): void {
		$list = $this->command->execute( array( 'list' ), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertIsArray( $list );
		$this->assertTrue( $list['success'] );

		$enable = $this->command->execute( array( 'enable', 'crm' ), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertWPError( $enable );
		$this->assertEquals( 'forbidden', $enable->get_error_code() );
	}

	/** Test list default action.
	 */
	public function test_list_default_action(): void {
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'servers', $result['data'] );
		$this->assertArrayHasKey( 'count', $result['data'] );
		$this->assertGreaterThan( 0, $result['data']['count'] );

		$slugs = wp_list_pluck( $result['data']['servers'], 'slug' );
		$this->assertContains( 'crm', $slugs );
	}

	/** Test list json envelope.
	 */
	public function test_list_json_envelope(): void {
		$result = $this->command->execute( array( 'list' ), array( 'json' => true ), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$decoded = json_decode( $result['message'], true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'servers', $decoded );
	}

	/** Test show known server.
	 */
	public function test_show_known_server(): void {
		$result = $this->command->execute( array( 'show', 'crm' ), array(), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'crm', $result['data']['slug'] );
		$this->assertArrayHasKey( 'tool_count', $result['data'] );
		$this->assertArrayHasKey( 'endpoints', $result['data'] );
	}

	/** Test show missing slug errors.
	 */
	public function test_show_missing_slug_errors(): void {
		$result = $this->command->execute( array( 'show' ), array(), array( 'user_id' => $this->admin_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_slug', $result->get_error_code() );
	}

	/** Test show unknown slug errors.
	 */
	public function test_show_unknown_slug_errors(): void {
		$result = $this->command->execute( array( 'show', 'nope' ), array(), array( 'user_id' => $this->admin_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'not_found', $result->get_error_code() );
	}

	/** Test enable then disable round trip.
	 */
	public function test_enable_then_disable_round_trip(): void {
		$disable = $this->command->execute( array( 'disable', 'crm' ), array(), array( 'user_id' => $this->admin_id ) );
		$this->assertIsArray( $disable );
		$this->assertFalse( $disable['data']['enabled'] );

		$enable = $this->command->execute( array( 'enable', 'crm' ), array(), array( 'user_id' => $this->admin_id ) );
		$this->assertIsArray( $enable );
		$this->assertTrue( $enable['data']['enabled'] );
	}

	/** Test tools subaction.
	 */
	public function test_tools_subaction(): void {
		$result = $this->command->execute( array( 'tools', 'crm' ), array(), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'crm', $result['data']['slug'] );
		$this->assertIsArray( $result['data']['tools'] );
		$this->assertEquals( count( $result['data']['tools'] ), $result['data']['count'] );
	}

	/** Test unknown action errors.
	 */
	public function test_unknown_action_errors(): void {
		$result = $this->command->execute( array( 'wat' ), array(), array( 'user_id' => $this->admin_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'unknown_action', $result->get_error_code() );
	}
}
