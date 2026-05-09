<?php
/**
 * Tests for update_term tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test update_term tool functionality.
 */
class Test_Tool_Update_Term extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Update_Term
	 */
	private $tool;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * A pre-existing category term ID.
	 *
	 * @var int
	 */
	private $term_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool     = new WP_MCP_AI_Tool_Update_Term();
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result        = wp_insert_term( 'Original Term ' . uniqid(), 'category' );
		$this->term_id = $result['term_id'];
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'update_term', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Unauthenticated call returns forbidden.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'term_id' => $this->term_id, 'taxonomy' => 'category', 'name' => 'New' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Missing term_id returns missing_term_id error.
	 */
	public function test_missing_term_id_returns_error() {
		$result = $this->tool->execute(
			array( 'taxonomy' => 'category', 'name' => 'New Name' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_term_id', $result->get_error_code() );
	}

	/**
	 * Missing taxonomy returns missing_taxonomy error.
	 */
	public function test_missing_taxonomy_returns_error() {
		$result = $this->tool->execute(
			array( 'term_id' => $this->term_id, 'name' => 'New Name' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_taxonomy', $result->get_error_code() );
	}

	/**
	 * Non-existent term returns term_not_found error.
	 */
	public function test_nonexistent_term_returns_error() {
		$result = $this->tool->execute(
			array( 'term_id' => 999999, 'taxonomy' => 'category', 'name' => 'Ghost' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_term_not_found', $result->get_error_code() );
	}

	/**
	 * Admin can update term name and description.
	 */
	public function test_admin_updates_term_successfully() {
		$new_name = 'Updated Term Name ' . uniqid();
		$result   = $this->tool->execute(
			array(
				'term_id'     => $this->term_id,
				'taxonomy'    => 'category',
				'name'        => $new_name,
				'description' => 'A test description',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $this->term_id, $result['term_id'] );
		$this->assertSame( $new_name, $result['name'] );
		$this->assertSame( 'A test description', $result['description'] );
	}

	/**
	 * Setting a term as its own parent returns invalid_parent error.
	 */
	public function test_self_parent_returns_error() {
		$result = $this->tool->execute(
			array(
				'term_id'  => $this->term_id,
				'taxonomy' => 'category',
				'parent'   => $this->term_id,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_parent', $result->get_error_code() );
	}

	/**
	 * Capability flags mark the tool as write and state-changing.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'state-changing', $flags );
	}
}
