<?php
/**
 * Tests for WP_MCP_AI_Tool_Spawn_Sub_Agent.
 *
 * @package WP_MCP_AI
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test suite for the spawn_sub_agent tool.
 *
 * @since 1.6.0
 */
class Test_Tool_Spawn_Sub_Agent extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Spawn_Sub_Agent
	 */
	protected $tool;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_Tool_Spawn_Sub_Agent' ) ) {
			$file = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-spawn-sub-agent.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			} else {
				$this->markTestSkipped( 'WP_MCP_AI_Tool_Spawn_Sub_Agent not found.' );
			}
		}
		$this->tool = new WP_MCP_AI_Tool_Spawn_Sub_Agent();
	}

	/**
	 * Test the tool slug.
	 */
	public function test_slug() {
		$this->assertSame( 'spawn_sub_agent', $this->tool->get_slug() );
	}

	/**
	 * Test capability flag expensive is true.
	 */
	public function test_capability_flag_expensive() {
		if ( ! method_exists( $this->tool, 'get_capability_flags' ) ) {
			$this->markTestSkipped( 'get_capability_flags not implemented.' );
		}
		$flags = $this->tool->get_capability_flags();
		$this->assertArrayHasKey( 'expensive', $flags );
		$this->assertTrue( $flags['expensive'] );
	}

	/**
	 * Test capability flag modifies-wp is true.
	 */
	public function test_capability_flag_modifies_wp() {
		if ( ! method_exists( $this->tool, 'get_capability_flags' ) ) {
			$this->markTestSkipped( 'get_capability_flags not implemented.' );
		}
		$flags = $this->tool->get_capability_flags();
		$this->assertArrayHasKey( 'modifies-wp', $flags );
		$this->assertTrue( $flags['modifies-wp'] );
	}

	/**
	 * Test that exceeding max_depth returns an error.
	 */
	public function test_returns_error_when_max_depth_exceeded() {
		$context = array(
			'spawn_depth' => 5,
			'user_id'     => 1,
		);
		$result = $this->tool->execute(
			array(
				'agent_id'  => 1,
				'task'      => 'Do something',
				'max_depth' => 3,
			),
			$context
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'depth', strtolower( $result['message'] ) );
	}

	/**
	 * Test that exceeding max_fanout returns an error.
	 */
	public function test_returns_error_when_max_fanout_exceeded() {
		$context = array(
			'spawn_depth'        => 1,
			'spawn_fanout_count' => 10,
			'user_id'            => 1,
		);
		$result = $this->tool->execute(
			array(
				'agent_id'   => 1,
				'task'       => 'Do something',
				'max_fanout' => 5,
			),
			$context
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'fanout', strtolower( $result['message'] ) );
	}

	/**
	 * Test that missing agent_id returns an error.
	 */
	public function test_returns_error_when_agent_id_missing() {
		$result = $this->tool->execute(
			array( 'task' => 'Do something' ),
			array( 'user_id' => 1 )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test that tool has a non-empty description.
	 */
	public function test_description_not_empty() {
		if ( method_exists( $this->tool, 'get_description' ) ) {
			$this->assertNotEmpty( $this->tool->get_description() );
		} else {
			$this->markTestSkipped( 'get_description not implemented.' );
		}
	}

	/**
	 * Test hooks fire on execute (before hook at minimum).
	 */
	public function test_before_hook_fires() {
		$fired = false;
		add_action(
			'wp_mcp_ai_before_spawn_sub_agent',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		// Will fail quickly (no agent_id) but hook should fire first.
		$this->tool->execute( array( 'agent_id' => 999, 'task' => 'test' ), array( 'user_id' => 1 ) );

		remove_all_actions( 'wp_mcp_ai_before_spawn_sub_agent' );
		$this->assertTrue( $fired );
	}
}
