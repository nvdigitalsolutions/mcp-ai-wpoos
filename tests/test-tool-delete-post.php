<?php
/**
 * Tests for delete_post tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test delete_post tool functionality.
 */
class Test_Tool_Delete_Post extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Delete_Post
	 */
	private $tool;

	/**
	 * Test user ID (editor).
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-delete-post.php';

		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Delete_Post();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'delete_post', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'post_id', $schema['required'] );
		$this->assertArrayHasKey( 'post_id', $schema['properties'] );
		$this->assertArrayHasKey( 'force_delete', $schema['properties'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'destructive', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}

	/**
	 * Test get_definition returns expected keys.
	 */
	public function test_get_definition() {
		$definition = $this->tool->get_definition();

		$this->assertIsArray( $definition );
		$this->assertArrayHasKey( 'toolkit', $definition );
		$this->assertArrayHasKey( 'risk_level', $definition );
		$this->assertEquals( 'content_publishing', $definition['toolkit'] );
		$this->assertEquals( 'high', $definition['risk_level'] );
	}

	/**
	 * Test that the tool returns an error when post_id is missing.
	 */
	public function test_missing_post_id_returns_error() {
		$result = $this->tool->execute( array(), array( 'user_id' => $this->user_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_missing_param', $result->get_error_code() );
	}

	/**
	 * Test that the tool returns an error when post does not exist.
	 */
	public function test_nonexistent_post_returns_error() {
		$result = $this->tool->execute(
			array( 'post_id' => 999999 ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_not_found', $result->get_error_code() );
	}

	/**
	 * Test that the tool trashes a post by default.
	 */
	public function test_trashes_post_by_default() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Post To Trash',
				'post_status' => 'publish',
			)
		);

		$result = $this->tool->execute(
			array( 'post_id' => $post_id ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertEquals( $post_id, $result['post_id'] );
		$this->assertFalse( $result['force_deleted'] );

		$post = get_post( $post_id );
		$this->assertEquals( 'trash', $post->post_status );
	}

	/**
	 * Test that force_delete permanently removes the post.
	 */
	public function test_force_delete_removes_post() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Post To Force Delete',
				'post_status' => 'publish',
			)
		);

		$result = $this->tool->execute(
			array(
				'post_id'      => $post_id,
				'force_delete' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['force_deleted'] );
		$this->assertNull( get_post( $post_id ) );
	}

	/**
	 * Test that protected post types cannot be deleted.
	 */
	public function test_protected_post_types_are_blocked() {
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_title' => 'Test Attachment',
			)
		);

		$result = $this->tool->execute(
			array( 'post_id' => $attachment_id ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that the tool returns an error for unauthenticated requests.
	 */
	public function test_no_permission_returns_error() {
		$result = $this->tool->execute(
			array( 'post_id' => 1 ),
			array( 'user_id' => 0 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that the tool implements the required interfaces.
	 */
	public function test_implements_interfaces() {
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $this->tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $this->tool );
	}
}
