<?php
/**
 * Tests that WP_MCP_AI_User_Context_Helper::safe_set_current_user() validates
 * its input before mutating the global current-user state.
 *
 * Background (B10): the WordPress.org reviewer flagged the base plugin's
 * `wp_set_current_user()` call sites and asked for hardening. Phase 3 of
 * the WP.org compliance lock-in (PR-C, continuation of #4900) routes every
 * base-plugin call through this helper. This regression test guarantees the
 * helper rejects invalid identifiers and keeps the previous user in place.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test file; PHPUnit naming convention.

/**
 * Unit tests for the user-context switch helper.
 */
class Test_User_Context_Helper extends WP_UnitTestCase {

	/**
	 * Reset the global current-user before each scenario so that one
	 * test cannot leak state into the next.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( 0 );
	}

	/**
	 * The helper must refuse to switch when given a falsy / negative ID.
	 */
	public function test_rejects_invalid_user_id() {
		$this->assertFalse( WP_MCP_AI_User_Context_Helper::safe_set_current_user( 0 ) );
		$this->assertFalse( WP_MCP_AI_User_Context_Helper::safe_set_current_user( -1 ) );
		$this->assertFalse( WP_MCP_AI_User_Context_Helper::safe_set_current_user( '' ) );
		$this->assertFalse( WP_MCP_AI_User_Context_Helper::safe_set_current_user( 'not-a-number' ) );
		$this->assertSame( 0, get_current_user_id(), 'Global current-user should remain anonymous after rejected switch.' );
	}

	/**
	 * A user identifier that does not resolve to a real WP_User must be
	 * rejected even if it is a positive integer (e.g. a stale ID stored in
	 * a transient or proxy header).
	 */
	public function test_rejects_nonexistent_user() {
		$this->assertFalse( WP_MCP_AI_User_Context_Helper::safe_set_current_user( 999999 ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * A real user must be accepted and the global current-user mutated.
	 */
	public function test_accepts_real_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( WP_MCP_AI_User_Context_Helper::safe_set_current_user( $user_id ) );
		$this->assertSame( $user_id, get_current_user_id() );
	}

	/**
	 * If the desired user is already current, the helper must report
	 * success without redundantly re-running `wp_set_current_user`.
	 */
	public function test_skip_if_already_current() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$this->assertTrue( WP_MCP_AI_User_Context_Helper::safe_set_current_user( $user_id ) );
		$this->assertSame( $user_id, get_current_user_id() );
	}

	/**
	 * After a deleted user identifier is supplied, the helper must
	 * reject the switch and leave the global state unchanged.
	 */
	public function test_rejects_deleted_user() {
		$user_id = self::factory()->user->create();
		wp_delete_user( $user_id );

		$baseline = get_current_user_id();
		$this->assertFalse( WP_MCP_AI_User_Context_Helper::safe_set_current_user( $user_id ) );
		$this->assertSame( $baseline, get_current_user_id() );
	}
}
