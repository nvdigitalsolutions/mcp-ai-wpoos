<?php
/**
 * Tests for get_recent_posts tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test get_recent_posts tool functionality.
 */
class Test_Tool_Get_Recent_Posts extends WP_UnitTestCase {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();
	}

	/**
	 * Test that get_recent_posts tool has the requires-capability flag.
	 */
	public function test_get_recent_posts_has_requires_capability_flag() {
		$flags = $this->registry->get_tool_capability_flags( 'get_recent_posts' );

		$this->assertIsArray( $flags, 'Should return an array of flags' );
		$this->assertContains(
			'requires-capability',
			$flags,
			'get_recent_posts tool should have the requires-capability flag'
		);
	}

	/**
	 * Test that get_recent_posts tool has appropriate capability flags.
	 */
	public function test_get_recent_posts_capability_flags() {
		$flags = $this->registry->get_tool_capability_flags( 'get_recent_posts' );

		$this->assertIsArray( $flags, 'Should return an array of flags' );
		$this->assertNotEmpty( $flags, 'Should have at least one flag' );

		// Verify specific flags that should be present.
		$expected_flags = array(
			'read-only',            // Only reads data.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capability.
			'cacheable',            // Results can be cached.
		);

		foreach ( $expected_flags as $expected_flag ) {
			$this->assertContains(
				$expected_flag,
				$flags,
				"get_recent_posts tool should have the {$expected_flag} flag"
			);
		}
	}

	/**
	 * Test that get_recent_posts tool implements the capability flags interface.
	 */
	public function test_get_recent_posts_implements_capability_flags_interface() {
		$tool = $this->registry->get_tool( 'get_recent_posts' );

		$this->assertNotNull( $tool, 'Tool should exist in registry' );
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Capability_Flags_Interface',
			$tool,
			'get_recent_posts should implement WP_MCP_AI_Tool_Capability_Flags_Interface'
		);
	}

	/**
	 * Test that get_recent_posts can be retrieved by requires-capability flag.
	 */
	public function test_get_recent_posts_retrievable_by_capability_flag() {
		$tools = $this->registry->get_tools_by_capability_flag( 'requires-capability' );

		$this->assertIsArray( $tools, 'Should return an array of tools' );

		// Find get_recent_posts in the list.
		$found = false;
		foreach ( $tools as $tool ) {
			if ( $tool->get_slug() === 'get_recent_posts' ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			'get_recent_posts should be in the list of tools with requires-capability flag'
		);
	}

	/**
	 * Test that get_recent_posts returns helpful message when no posts exist.
	 */
	public function test_get_recent_posts_returns_message_when_no_posts() {
		// Create a user with 'read' capability.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber', // Subscribers can read.
			)
		);

		$tool = $this->registry->get_tool( 'get_recent_posts' );
		$this->assertNotNull( $tool, 'Tool should exist in registry' );

		// Execute with no posts in the database.
		$result = $tool->execute(
			array( 'limit' => 5 ),
			array( 'user_id' => $user_id )
		);

		// Should return an array with message and count.
		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertArrayHasKey( 'message', $result, 'Should have a message key' );
		$this->assertArrayHasKey( 'count', $result, 'Should have a count key' );
		$this->assertSame( 0, $result['count'], 'Count should be 0' );
		$this->assertStringContainsString( 'No published', $result['message'], 'Message should indicate no posts found' );
	}

	/**
	 * Test that get_recent_posts returns posts when they exist.
	 */
	public function test_get_recent_posts_returns_posts_when_exist() {
		// Create a user with 'read' capability.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		// Create some test posts.
		$post_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$post_ids[] = $this->factory->post->create(
				array(
					'post_title'   => "Test Post {$i}",
					'post_content' => "This is test content for post {$i}",
					'post_status'  => 'publish',
				)
			);
		}

		$tool = $this->registry->get_tool( 'get_recent_posts' );
		$this->assertNotNull( $tool, 'Tool should exist in registry' );

		// Execute the tool.
		$result = $tool->execute(
			array( 'limit' => 5 ),
			array( 'user_id' => $user_id )
		);

		// Should return an array of posts.
		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertCount( 3, $result, 'Should return 3 posts' );

		// Verify structure of first post.
		$this->assertArrayHasKey( 'ID', $result[0], 'Post should have ID' );
		$this->assertArrayHasKey( 'title', $result[0], 'Post should have title' );
		$this->assertArrayHasKey( 'permalink', $result[0], 'Post should have permalink' );
		$this->assertArrayHasKey( 'excerpt', $result[0], 'Post should have excerpt' );
		$this->assertArrayHasKey( 'date', $result[0], 'Post should have date' );
	}

	/**
	 * Test that get_recent_posts requires read capability.
	 */
	public function test_get_recent_posts_requires_read_capability() {
		$tool = $this->registry->get_tool( 'get_recent_posts' );
		$this->assertNotNull( $tool, 'Tool should exist in registry' );

		// Execute without a user (user_id = 0).
		$result = $tool->execute(
			array( 'limit' => 5 ),
			array( 'user_id' => 0 )
		);

		// Should return WP_Error for permission denied.
		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for no user' );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code(), 'Should have forbidden error code' );
	}
}
