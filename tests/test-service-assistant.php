<?php
/**
 * Tests for WP_MCP_AI_Assistant_Service.
 *
 * Covers assistant validation, access control, configuration retrieval,
 * default assistant resolution, and assistants list queries.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Assistant_Service.
 */
class Test_Service_Assistant extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var WP_MCP_AI_Assistant_Service
	 */
	private $service;

	/**
	 * Admin user ID used in tests.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->service  = new WP_MCP_AI_Assistant_Service();
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		$this->service  = null;
		$this->admin_id = 0;
		parent::tearDown();
	}

	/**
	 * Test that validate_assistant_access returns WP_Error when ID is zero.
	 */
	public function test_validate_assistant_access_returns_error_for_zero_id() {
		$result = $this->service->validate_assistant_access( 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_assistant', $result->get_error_code() );
	}

	/**
	 * Test that validate_assistant_access returns WP_Error for a non-existent post ID.
	 */
	public function test_validate_assistant_access_returns_error_for_nonexistent_post() {
		$result = $this->service->validate_assistant_access( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_assistant', $result->get_error_code() );
	}

	/**
	 * Test that validate_assistant_access returns WP_Error for a non-assistant post type.
	 */
	public function test_validate_assistant_access_returns_error_for_wrong_post_type() {
		$post_id = $this->factory->post->create( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
		) );

		$result = $this->service->validate_assistant_access( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_assistant', $result->get_error_code() );
	}

	/**
	 * Test that validate_assistant_access returns WP_Error for an unpublished assistant.
	 */
	public function test_validate_assistant_access_returns_error_for_draft_assistant() {
		$post_id = $this->factory->post->create( array(
			'post_type'   => 'mcp_ai_assistant',
			'post_status' => 'draft',
		) );

		$result = $this->service->validate_assistant_access( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_assistant_not_published', $result->get_error_code() );
	}

	/**
	 * Test that validate_assistant_access returns the WP_Post for a valid published assistant.
	 */
	public function test_validate_assistant_access_returns_post_for_valid_assistant() {
		$post_id = $this->factory->post->create( array(
			'post_type'   => 'mcp_ai_assistant',
			'post_status' => 'publish',
			'post_title'  => 'Test Assistant',
		) );

		$result = $this->service->validate_assistant_access( $post_id );

		$this->assertInstanceOf( WP_Post::class, $result );
		$this->assertSame( $post_id, $result->ID );
	}

	/**
	 * Test that resolve_assistant_id returns the supplied ID unchanged.
	 */
	public function test_resolve_assistant_id_returns_supplied_id() {
		$result = $this->service->resolve_assistant_id( 42 );
		$this->assertSame( 42, $result );
	}

	/**
	 * Test that resolve_assistant_id returns null when no ID and no default is set.
	 */
	public function test_resolve_assistant_id_returns_null_without_default() {
		$result = $this->service->resolve_assistant_id( null );
		// Null or falsy when no default is configured.
		$this->assertFalse( (bool) $result );
	}

	/**
	 * Test that get_assistants_list returns an array.
	 */
	public function test_get_assistants_list_returns_array() {
		$list = $this->service->get_assistants_list();
		$this->assertIsArray( $list );
	}

	/**
	 * Test that get_assistants_list includes a created assistant.
	 */
	public function test_get_assistants_list_includes_published_assistant() {
		$post_id = $this->factory->post->create( array(
			'post_type'   => 'mcp_ai_assistant',
			'post_status' => 'publish',
			'post_title'  => 'Listed Assistant',
		) );

		$list = $this->service->get_assistants_list();

		$ids = array_column( $list, 'id' );
		$this->assertContains( $post_id, $ids );
	}

	/**
	 * Test that a guest user (ID 0) is denied access to a capability-gated assistant.
	 */
	public function test_validate_assistant_access_denies_guest_for_capability_gated_assistant() {
		$post_id = $this->factory->post->create( array(
			'post_type'   => 'mcp_ai_assistant',
			'post_status' => 'publish',
		) );

		// Use a custom capability that only contains [a-z_] so it passes
		// sanitize_required_capability_meta() and is actually stored.
		// The bootstrap's user_has_cap filter only grants a handful of standard
		// WP capabilities; this custom one is never granted to anyone.
		$custom_cap = 'wp_mcp_ai_test_secret_gate_cap';
		update_post_meta( $post_id, 'mcp_ai_required_capability', $custom_cap );

		// Sanity: confirm the meta is stored (sanitizer accepts [a-z_] values).
		$stored = get_post_meta( $post_id, 'mcp_ai_required_capability', true );
		if ( $stored !== $custom_cap ) {
			$this->markTestSkipped( 'Meta sanitizer rejected test capability — review sanitize_required_capability_meta().' );
		}

		wp_set_current_user( 0 );

		$result = $this->service->validate_assistant_access( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_insufficient_permissions', $result->get_error_code() );
	}
}
