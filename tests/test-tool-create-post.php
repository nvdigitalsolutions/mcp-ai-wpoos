<?php
/**
 * Tests for create_post tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test create_post tool functionality.
 */
class Test_Tool_Create_Post extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Create_Post
	 */
	private $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->tool     = new WP_MCP_AI_Tool_Create_Post();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'create_post', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Missing title returns wp_mcp_ai_missing_title error.
	 */
	public function test_missing_title_returns_error() {
		$result = $this->tool->execute(
			array( 'content' => 'Hello world.' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_title', $result->get_error_code() );
	}

	/**
	 * Missing content returns wp_mcp_ai_missing_content error.
	 */
	public function test_missing_content_returns_error() {
		$result = $this->tool->execute(
			array( 'title' => 'Test Post' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_content', $result->get_error_code() );
	}

	/**
	 * Invalid post type returns wp_mcp_ai_invalid_post_type error.
	 */
	public function test_invalid_post_type_returns_error() {
		$result = $this->tool->execute(
			array(
				'title'     => 'Test Post',
				'content'   => 'Hello world.',
				'post_type' => 'nonexistent_post_type_' . uniqid(),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_post_type', $result->get_error_code() );
	}

	/**
	 * Valid args create the post and return its ID and metadata.
	 */
	public function test_valid_args_create_post_successfully() {
		$unique = 'test-create-' . uniqid();

		$result = $this->tool->execute(
			array(
				'title'   => 'PR7 Test: ' . $unique,
				'content' => 'This post was created by the PHPUnit test suite.',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ID', $result );
		$this->assertGreaterThan( 0, $result['ID'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'post', $result['post_type'] );

		// Verify the post actually exists in the DB.
		$post = get_post( $result['ID'] );
		$this->assertNotNull( $post );
		$this->assertStringContainsString( 'PR7 Test', $post->post_title );
	}

	/**
	 * Created post can target the 'page' post type.
	 */
	public function test_creates_page_post_type() {
		$result = $this->tool->execute(
			array(
				'title'     => 'PR7 Page ' . uniqid(),
				'content'   => 'Page body.',
				'post_type' => 'page',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'page', $result['post_type'] );
	}

	/**
	 * Status parameter is respected (publish).
	 */
	public function test_status_parameter_is_respected() {
		$result = $this->tool->execute(
			array(
				'title'   => 'Published Post ' . uniqid(),
				'content' => 'Published content.',
				'status'  => 'publish',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );
	}
}
