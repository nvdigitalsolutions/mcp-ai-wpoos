<?php
/**
 * Tests for WP_MCP_AI_WP_Capability_Checker
 *
 * Verifies that the Capability Checker adapter correctly wraps WordPress
 * `current_user_can` and `user_can` functions, satisfies the interface
 * contract, and handles valid/invalid users as expected.
 *
 * @package WP_MCP_AI
 * @group   infrastructure
 * @group   capability-checker
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for WP_MCP_AI_WP_Capability_Checker.
 */
class Test_WP_MCP_AI_WP_Capability_Checker extends WP_UnitTestCase {

	/**
	 * SUT instance.
	 *
	 * @var WP_MCP_AI_WP_Capability_Checker
	 */
	private $checker;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber (low-privilege) user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Previously logged-in user ID restored during tearDown.
	 *
	 * @var int
	 */
	private $previous_user_id;

	/**
	 * Create test users and set up the checker.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->checker          = new WP_MCP_AI_WP_Capability_Checker();
		$this->previous_user_id = get_current_user_id();

		$this->admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Restore the previously active user.
	 */
	public function tearDown(): void {
		wp_set_current_user( $this->previous_user_id );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Interface contract
	// -------------------------------------------------------------------------

	/**
	 * The class should implement the capability checker interface.
	 */
	public function test_implements_interface() {
		$this->assertInstanceOf( Interface_WP_MCP_AI_Capability_Checker::class, $this->checker );
	}

	// -------------------------------------------------------------------------
	// current_user_can()
	// -------------------------------------------------------------------------

	/**
	 * Administrator has the `manage_options` capability.
	 */
	public function test_current_user_can_admin_has_manage_options() {
		wp_set_current_user( $this->admin_id );

		$this->assertTrue( $this->checker->current_user_can( 'manage_options' ) );
	}

	/**
	 * Subscriber does not have the `manage_options` capability.
	 */
	public function test_current_user_can_subscriber_lacks_manage_options() {
		wp_set_current_user( $this->subscriber_id );

		$this->assertFalse( $this->checker->current_user_can( 'manage_options' ) );
	}

	/**
	 * Administrator has the `edit_posts` capability.
	 */
	public function test_current_user_can_admin_has_edit_posts() {
		wp_set_current_user( $this->admin_id );

		$this->assertTrue( $this->checker->current_user_can( 'edit_posts' ) );
	}

	/**
	 * Subscriber has the `read` capability (base WordPress capability for all
	 * roles).
	 */
	public function test_current_user_can_subscriber_has_read() {
		wp_set_current_user( $this->subscriber_id );

		$this->assertTrue( $this->checker->current_user_can( 'read' ) );
	}

	/**
	 * Unauthenticated (user ID 0) does not have `edit_posts`.
	 */
	public function test_current_user_can_unauthenticated_lacks_edit_posts() {
		wp_set_current_user( 0 );

		$this->assertFalse( $this->checker->current_user_can( 'edit_posts' ) );
	}

	/**
	 * An unknown capability is denied for the subscriber.
	 */
	public function test_current_user_can_returns_false_for_unknown_cap() {
		wp_set_current_user( $this->subscriber_id );

		$this->assertFalse( $this->checker->current_user_can( 'wp_mcp_ai_nonexistent_cap_xyz' ) );
	}

	/**
	 * current_user_can() returns a boolean, not a truthy/falsy mixed value.
	 */
	public function test_current_user_can_returns_bool_for_admin() {
		wp_set_current_user( $this->admin_id );
		$result = $this->checker->current_user_can( 'manage_options' );

		$this->assertIsBool( $result );
	}

	/**
	 * current_user_can() returns a boolean for subscriber.
	 */
	public function test_current_user_can_returns_bool_for_subscriber() {
		wp_set_current_user( $this->subscriber_id );
		$result = $this->checker->current_user_can( 'manage_options' );

		$this->assertIsBool( $result );
	}

	/**
	 * current_user_can() respects a capability that includes extra object args
	 * (e.g. editing a specific post by ID).
	 */
	public function test_current_user_can_with_post_id_arg() {
		wp_set_current_user( $this->admin_id );

		$post_id = $this->factory->post->create( array( 'post_author' => $this->admin_id ) );

		$this->assertTrue( $this->checker->current_user_can( 'edit_post', $post_id ) );
	}

	// -------------------------------------------------------------------------
	// user_can()
	// -------------------------------------------------------------------------

	/**
	 * Administrator has `manage_options` when checked by user ID.
	 */
	public function test_user_can_admin_has_manage_options() {
		$result = $this->checker->user_can( $this->admin_id, 'manage_options' );

		$this->assertTrue( $result );
	}

	/**
	 * Subscriber lacks `manage_options` when checked by user ID.
	 */
	public function test_user_can_subscriber_lacks_manage_options() {
		$result = $this->checker->user_can( $this->subscriber_id, 'manage_options' );

		$this->assertFalse( $result );
	}

	/**
	 * Administrator has `delete_posts` when checked by user ID.
	 */
	public function test_user_can_admin_has_delete_posts() {
		$result = $this->checker->user_can( $this->admin_id, 'delete_posts' );

		$this->assertTrue( $result );
	}

	/**
	 * Subscriber has `read` when checked by user ID.
	 */
	public function test_user_can_subscriber_has_read() {
		$result = $this->checker->user_can( $this->subscriber_id, 'read' );

		$this->assertTrue( $result );
	}

	/**
	 * user_can() with a non-existent user ID returns false rather than
	 * throwing an exception.
	 */
	public function test_user_can_returns_false_for_nonexistent_user() {
		$result = $this->checker->user_can( PHP_INT_MAX, 'read' );

		$this->assertFalse( $result );
	}

	/**
	 * user_can() returns false for user ID 0 (unauthenticated sentinel).
	 */
	public function test_user_can_returns_false_for_user_id_zero() {
		$result = $this->checker->user_can( 0, 'edit_posts' );

		$this->assertFalse( $result );
	}

	/**
	 * user_can() is independent of the currently logged-in user.
	 *
	 * Confirms that checking a subscriber's capabilities while an admin is
	 * logged in correctly reflects the subscriber's permissions, not the
	 * admin's.
	 */
	public function test_user_can_is_independent_of_current_user() {
		// Log in as admin.
		wp_set_current_user( $this->admin_id );

		// But check the subscriber's capabilities.
		$result = $this->checker->user_can( $this->subscriber_id, 'manage_options' );

		$this->assertFalse( $result );
	}

	/**
	 * user_can() returns a boolean, not a truthy/falsy mixed value.
	 */
	public function test_user_can_returns_bool() {
		$result = $this->checker->user_can( $this->admin_id, 'manage_options' );

		$this->assertIsBool( $result );
	}

	/**
	 * user_can() respects extra object args (e.g. editing a specific post).
	 */
	public function test_user_can_with_post_id_arg() {
		$post_id = $this->factory->post->create( array( 'post_author' => $this->admin_id ) );

		$this->assertTrue( $this->checker->user_can( $this->admin_id, 'edit_post', $post_id ) );
		$this->assertFalse( $this->checker->user_can( $this->subscriber_id, 'edit_post', $post_id ) );
	}
}
