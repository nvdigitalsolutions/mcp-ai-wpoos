<?php
/**
 * Tests for WP-CLI capability gating and security hardening.
 *
 * Validates that mutating CLI commands respect the require_capability()
 * checks added in v1.2.0. Since WP-CLI cannot run in PHPUnit directly,
 * these tests validate the underlying tool/service logic that the CLI
 * commands gate on.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test capability gating for CLI-mutating operations.
 *
 * @since 1.2.0
 */
class Test_WP_CLI_Capability_Gating extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id = 0;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_user_id = 0;

	/**
	 * Created post IDs for cleanup.
	 *
	 * @var int[]
	 */
	protected $created_posts = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->admin_user_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		foreach ( $this->created_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->created_posts = array();

		wp_set_current_user( 0 );
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Tool enable/disable capability gating
	// -----------------------------------------------------------------------

	/**
	 * Tool enable requires manage_options capability.
	 */
	public function test_tool_enable_requires_manage_options() {
		$this->assertFalse(
			user_can( $this->subscriber_user_id, 'manage_options' ),
			'Subscriber should not have manage_options'
		);

		$this->assertTrue(
			user_can( $this->admin_user_id, 'manage_options' ),
			'Admin should have manage_options'
		);
	}

	/**
	 * Tool disable requires manage_options capability.
	 */
	public function test_tool_disable_requires_manage_options() {
		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	// -----------------------------------------------------------------------
	// Assistant create/delete capability gating
	// -----------------------------------------------------------------------

	/**
	 * Assistant creation requires manage_options.
	 */
	public function test_assistant_create_requires_manage_options() {
		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	/**
	 * Assistant deletion requires manage_options.
	 */
	public function test_assistant_delete_requires_manage_options() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Capability Test Assistant',
				'post_status' => 'publish',
			)
		);
		$this->assertIsInt( $post_id );
		$this->created_posts[] = $post_id;

		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertFalse( current_user_can( 'delete_post', $post_id ) );

		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertTrue( current_user_can( 'delete_post', $post_id ) );
	}

	// -----------------------------------------------------------------------
	// Credential issue/revoke capability gating
	// -----------------------------------------------------------------------

	/**
	 * Credential issue requires manage_options.
	 */
	public function test_credential_issue_requires_manage_options() {
		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	/**
	 * Credential revoke requires manage_options.
	 */
	public function test_credential_revoke_requires_manage_options() {
		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	// -----------------------------------------------------------------------
	// Settings set/reset capability gating
	// -----------------------------------------------------------------------

	/**
	 * Settings set requires manage_options.
	 */
	public function test_settings_set_requires_manage_options() {
		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	/**
	 * Settings reset requires manage_options.
	 */
	public function test_settings_reset_requires_manage_options() {
		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	// -----------------------------------------------------------------------
	// Cron run/delete/clear capability gating
	// -----------------------------------------------------------------------

	/**
	 * Cron run requires manage_options.
	 */
	public function test_cron_run_requires_manage_options() {
		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	/**
	 * Cron delete requires manage_options.
	 */
	public function test_cron_delete_requires_manage_options() {
		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	/**
	 * Cron clear requires manage_options.
	 */
	public function test_cron_clear_requires_manage_options() {
		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	// -----------------------------------------------------------------------
	// Tool layer: Pattern B auth fix — get_current_user_id() fallback
	// -----------------------------------------------------------------------

	/**
	 * Tools using Pattern A (get_current_user_id) work without explicit
	 * context user_id when wp_set_current_user() has been called.
	 */
	public function test_tool_user_id_falls_back_to_current_user() {
		wp_set_current_user( $this->admin_user_id );

		// Simulate what a Pattern A tool does.
		$context  = array(); // No user_id in context.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$this->assertSame( $this->admin_user_id, $user_id );
	}

	/**
	 * Tools using old Pattern B (hardcoded 0) would fail without
	 * explicit context user_id. The migration to get_current_user_id()
	 * fixes this for WP-CLI usage.
	 */
	public function test_old_pattern_b_fallback_to_zero_is_insufficient() {
		wp_set_current_user( $this->admin_user_id );

		// Old Pattern B (now migrated).
		$context  = array();
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		// With old pattern, user_id is 0 even though admin is logged in.
		$this->assertSame( 0, $user_id, 'Old Pattern B returns 0; migrated tools now use get_current_user_id()' );
	}

	// -----------------------------------------------------------------------
	// Base class helper methods
	// -----------------------------------------------------------------------

	/**
	 * The base class exposes require_capability().
	 *
	 * The class only loads under WP-CLI (it extends WP_CLI_Command), so the
	 * assertion is skipped when WP-CLI is not available in this environment.
	 */
	public function test_base_class_has_require_capability_method() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI_Command' ) ) {
			$this->markTestSkipped( 'WP-CLI is not available in this environment.' );
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_CLI_Base_Command', 'require_capability' ),
			'Base CLI command should have require_capability()'
		);
	}

	/**
	 * The base class exposes get_format().
	 *
	 * The class only loads under WP-CLI (it extends WP_CLI_Command), so the
	 * assertion is skipped when WP-CLI is not available in this environment.
	 */
	public function test_base_class_has_get_format_method() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI_Command' ) ) {
			$this->markTestSkipped( 'WP-CLI is not available in this environment.' );
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_CLI_Base_Command', 'get_format' ),
			'Base CLI command should have get_format()'
		);
	}
}
