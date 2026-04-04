<?php
/**
 * Tests for get_post tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test get_post tool functionality.
 */
class Test_Tool_Get_Post extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Get_Post
	 */
	private $tool;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-get-post.php';

		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Get_Post();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'get_post', $this->tool->get_slug() );
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
		$this->assertArrayHasKey( 'include_meta', $schema['properties'] );
		$this->assertArrayHasKey( 'include_taxonomies', $schema['properties'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'cacheable', $flags );
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
		$this->assertEquals( 'info', $definition['risk_level'] );
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
	 * Test that the tool retrieves an existing post.
	 */
	public function test_returns_existing_post() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => 'Test content',
				'post_status'  => 'publish',
			)
		);

		$result = $this->tool->execute(
			array( 'post_id' => $post_id ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertEquals( $post_id, $result['ID'] );
		$this->assertEquals( 'Test Post', $result['title'] );
		$this->assertEquals( 'post', $result['post_type'] );
		$this->assertArrayHasKey( 'permalink', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Test that meta is included by default.
	 */
	public function test_meta_included_by_default() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Meta Test',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, 'my_field', 'my_value' );

		$result = $this->tool->execute(
			array( 'post_id' => $post_id ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertArrayHasKey( 'meta', $result );
		$this->assertArrayHasKey( 'my_field', $result['meta'] );
		$this->assertEquals( 'my_value', $result['meta']['my_field'] );
	}

	/**
	 * Test that meta can be excluded.
	 */
	public function test_meta_excluded_when_requested() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'No Meta Test',
				'post_status' => 'publish',
			)
		);

		$result = $this->tool->execute(
			array(
				'post_id'      => $post_id,
				'include_meta' => false,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertArrayNotHasKey( 'meta', $result );
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
