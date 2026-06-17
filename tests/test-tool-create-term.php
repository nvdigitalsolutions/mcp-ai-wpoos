<?php
/**
 * Tests for create_term tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test create_term tool functionality.
 */
class Test_Tool_Create_Term extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Create_Term
	 */
	private $tool;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber (low-priv) user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool = new WP_MCP_AI_Tool_Create_Term();

		$this->admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'create_term', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Unauthenticated call returns forbidden error.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'name' => 'Test Category', 'taxonomy' => 'category' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Missing name returns missing_name error.
	 */
	public function test_missing_name_returns_error() {
		$result = $this->tool->execute(
			array( 'name' => '', 'taxonomy' => 'category' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_name', $result->get_error_code() );
	}

	/**
	 * Invalid taxonomy returns invalid_taxonomy error.
	 */
	public function test_invalid_taxonomy_returns_error() {
		$result = $this->tool->execute(
			array( 'name' => 'Test', 'taxonomy' => 'nonexistent_taxonomy_xyz' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_taxonomy', $result->get_error_code() );
	}

	/**
	 * Subscriber cannot create categories (no manage_categories cap).
	 */
	public function test_subscriber_cannot_create_term() {
		$result = $this->tool->execute(
			array( 'name' => 'Subscriber Term', 'taxonomy' => 'category' ),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Admin can create a category and returns correct shape.
	 */
	public function test_admin_creates_category_successfully() {
		$result = $this->tool->execute(
			array( 'name' => 'PHPUnit Test Category ' . uniqid(), 'taxonomy' => 'category' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'term_id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'slug', $result );
		$this->assertSame( 'category', $result['taxonomy'] );
		$this->assertGreaterThan( 0, $result['term_id'] );
	}

	/**
	 * Duplicate name returns term_exists error.
	 */
	public function test_duplicate_term_returns_error() {
		$name = 'Duplicate Term ' . uniqid();
		// First call should succeed.
		$first = $this->tool->execute(
			array( 'name' => $name, 'taxonomy' => 'category' ),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertIsArray( $first );

		// Second call with same name should fail.
		$second = $this->tool->execute(
			array( 'name' => $name, 'taxonomy' => 'category' ),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertWPError( $second );
		$this->assertSame( 'wp_mcp_ai_term_exists', $second->get_error_code() );
	}

	/**
	 * Creating term with valid slug uses that slug.
	 */
	public function test_custom_slug_is_applied() {
		$slug = 'custom-slug-' . uniqid();
		$result = $this->tool->execute(
			array(
				'name'     => 'Term With Slug ' . uniqid(),
				'taxonomy' => 'post_tag',
				'slug'     => $slug,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $slug, $result['slug'] );
	}

	/**
	 * Invalid parent returns invalid_parent error.
	 */
	public function test_invalid_parent_returns_error() {
		$result = $this->tool->execute(
			array(
				'name'     => 'Child Category ' . uniqid(),
				'taxonomy' => 'category',
				'parent'   => 999999,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_parent', $result->get_error_code() );
	}

	/**
	 * Capability flags include 'write' and 'state-changing'.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'state-changing', $flags );
	}
}
