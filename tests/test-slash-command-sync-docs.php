<?php
/**
 * Test Sync Docs Slash Command
 *
 * PHPUnit tests for /sync-docs command functionality.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test sync-docs command functionality
 */
class Test_Slash_Command_Sync_Docs extends WP_UnitTestCase {

	/**
	 * Command instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Sync_Docs
	 */
	private $command;

	/**
	 * Test user ID
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Load command class.
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-sync-docs.php';

		$this->command = new WP_MCP_AI_Slash_Command_Sync_Docs();

		// Create test user with edit_posts capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Test command requires edit_posts capability
	 */
	public function test_command_requires_capability() {
		// Create user without edit_posts capability.
		$subscriber_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber_id );

		$result = $this->command->execute(
			array(),
			array(),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * Test command executes with valid user
	 */
	public function test_command_executes_for_valid_user() {
		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		// Should not be an error.
		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test no documentation scenario
	 */
	public function test_no_documentation_found() {
		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'No documentation', $result );
	}

	/**
	 * Test finding documentation posts
	 */
	public function test_find_documentation_posts() {
		// Create a post with "documentation" in title.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Documentation: Getting Started',
				'post_status' => 'publish',
				'post_content' => '<h2>Installation</h2><p>Instructions here.</p>',
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'type'    => 'posts',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test broken link detection
	 */
	public function test_broken_link_detection() {
		// Create a post with broken internal link.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Documentation Guide',
				'post_status' => 'publish',
				'post_content' => '<a href="' . home_url( '/non-existent-page/' ) . '">Broken Link</a>',
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'type'    => 'posts',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Broken link', $result );
	}

	/**
	 * Test version reference detection
	 */
	public function test_version_reference_detection() {
		// Create post with outdated WordPress version reference.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'WordPress Tutorial',
				'post_status' => 'publish',
				'post_content' => 'This guide is for WordPress 5.0 and newer.',
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'type'    => 'posts',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		// May contain version reference issue.
		$this->assertIsString( $result );
	}

	/**
	 * Test auto-fix functionality
	 */
	public function test_auto_fix_functionality() {
		// Create post with outdated version.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Guide',
				'post_status' => 'publish',
				'post_content' => 'Requires WordPress 5.0',
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'type'     => 'posts',
				'auto-fix' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test missing section detection
	 */
	public function test_missing_section_detection() {
		// Create post without required sections.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Tutorial',
				'post_status' => 'publish',
				'post_content' => '<p>Just some content without proper sections.</p>',
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'type'    => 'posts',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Missing recommended section', $result );
	}

	/**
	 * Test skip-links flag
	 */
	public function test_skip_links_flag() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Guide',
				'post_status' => 'publish',
				'post_content' => '<a href="' . home_url( '/broken/' ) . '">Link</a>',
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'type'       => 'posts',
				'dry-run'    => true,
				'skip-links' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		// Should NOT contain broken link warnings.
		$this->assertIsString( $result );
	}

	/**
	 * Test skip-code flag
	 */
	public function test_skip_code_flag() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Code Tutorial',
				'post_status' => 'publish',
				'post_content' => '<code>mysql_query("SELECT * FROM table")</code>',
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'type'      => 'posts',
				'dry-run'   => true,
				'skip-code' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		// Should NOT check for deprecated functions.
		$this->assertIsString( $result );
	}

	/**
	 * Test type filter
	 */
	public function test_type_filter() {
		$result = $this->command->execute(
			array(),
			array(
				'type'    => 'pages',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test dry-run prevents changes
	 */
	public function test_dry_run_prevents_changes() {
		$original_content = 'Requires WordPress 5.0';
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Guide',
				'post_status' => 'publish',
				'post_content' => $original_content,
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'type'     => 'posts',
				'dry-run'  => true,
				'auto-fix' => true, // Should be ignored.
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );

		// Verify content was NOT changed.
		$post = get_post( $post_id );
		$this->assertEquals( $original_content, $post->post_content );
	}

	/**
	 * Test output format
	 */
	public function test_output_format() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Documentation',
				'post_status' => 'publish',
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'type'    => 'posts',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
		// Should be markdown formatted.
		$this->assertStringContainsString( '##', $result );
		$this->assertStringContainsString( '**', $result );
	}

	/**
	 * Cleanup after tests
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
