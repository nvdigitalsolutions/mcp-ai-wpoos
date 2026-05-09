<?php
/**
 * Tests for query_remote_site tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test query_remote_site tool functionality.
 */
class Test_Tool_Query_Remote_Site extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Query_Remote_Site
	 */
	private $tool;

	/**
	 * Administrator user ID.
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

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool      = new WP_MCP_AI_Tool_Query_Remote_Site();
		$this->admin_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Ensure mesh networking is disabled by default (no external calls).
		update_option( 'wp_mcp_ai_settings', array( 'enable_mesh' => false ) );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'query_remote_site', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Unauthenticated call returns forbidden.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'peer_name' => 'site-a', 'prompt' => 'Hello' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Admin but mesh disabled returns mesh_disabled error.
	 */
	public function test_mesh_disabled_returns_error() {
		$result = $this->tool->execute(
			array( 'peer_name' => 'site-a', 'prompt' => 'Hello' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mesh_disabled', $result->get_error_code() );
	}

	/**
	 * Mesh enabled but peer not configured returns peer_not_found.
	 */
	public function test_peer_not_found_returns_error() {
		update_option( 'wp_mcp_ai_settings', array(
			'enable_mesh'     => true,
			'mesh_peer_sites' => array(),
		) );

		$result = $this->tool->execute(
			array( 'peer_name' => 'nonexistent-peer', 'prompt' => 'Hello' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_peer_not_found', $result->get_error_code() );
	}

	/**
	 * Missing peer_name returns missing_peer_name error when mesh is enabled.
	 */
	public function test_missing_peer_name_returns_error() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_mesh' => true ) );

		$result = $this->tool->execute(
			array( 'prompt' => 'A prompt but no peer' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_peer_name', $result->get_error_code() );
	}

	/**
	 * Missing prompt returns missing_prompt error when mesh is enabled.
	 */
	public function test_missing_prompt_returns_error() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_mesh' => true ) );

		$result = $this->tool->execute(
			array( 'peer_name' => 'site-a' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}
}
