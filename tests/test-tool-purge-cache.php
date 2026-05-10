<?php
/**
 * Tests for purge_cache tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test purge_cache tool functionality.
 */
class Test_Tool_Purge_Cache extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Purge_Cache
	 */
	private $tool;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID (no manage_options).
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool      = new WP_MCP_AI_Tool_Purge_Cache();
		$this->admin_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'purge_cache', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Unauthenticated call returns forbidden.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'purge_everything' => true ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Admin without specifying what to purge gets empty_purge error.
	 */
	public function test_empty_purge_args_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_empty_purge', $result->get_error_code() );
	}

	/**
	 * Admin with no cache layers configured gets no_cache_layers error.
	 *
	 * The test site has no Cloudflare/Varnish credentials, so the tool
	 * should report that no layers are configured.
	 */
	public function test_no_cache_layers_configured_returns_error() {
		// Ensure no cache settings are configured.
		update_option( 'wp_mcp_ai_settings', array(
			'cloudflare_api_key' => '',
			'varnish_host'       => '',
		) );

		$result = $this->tool->execute(
			array( 'purge_everything' => true ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_no_cache_layers', $result->get_error_code() );
	}

	/**
	 * Capability flags include 'state-changing' and 'requires-capability'.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}
}
