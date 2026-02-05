<?php
/**
 * Test Ship Slash Command
 *
 * PHPUnit tests for /ship command functionality.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test ship command functionality
 */
class Test_Slash_Command_Ship extends WP_UnitTestCase {

	/**
	 * Command instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Ship
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
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-ship.php';

		$this->command = new WP_MCP_AI_Slash_Command_Ship();

		// Create test user with publish_posts capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Test command requires publish_posts capability
	 */
	public function test_command_requires_capability() {
		// Create user without publish_posts capability.
		$contributor_id = $this->factory->user->create(
			array(
				'role' => 'contributor',
			)
		);
		wp_set_current_user( $contributor_id );

		$result = $this->command->execute(
			array(),
			array(),
			array( 'user_id' => $contributor_id )
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
	 * Test dry-run flag
	 */
	public function test_dry_run_flag() {
		// Create a draft post.
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->user_id,
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'dry run', $result );
	}

	/**
	 * Test pre-flight checks detect issues
	 */
	public function test_preflight_checks() {
		// Create post without featured image or categories.
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'draft',
				'post_author'  => $this->user_id,
				'post_content' => 'Short content',
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Issues to Address', $result );
	}

	/**
	 * Test SEO checks
	 */
	public function test_seo_checks() {
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->user_id,
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		// Should report SEO issues.
		$this->assertIsString( $result );
	}

	/**
	 * Test skip-checks flag
	 */
	public function test_skip_checks_flag() {
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->user_id,
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'dry-run'     => true,
				'skip-checks' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test publish flag with high readiness score
	 */
	public function test_publish_flag() {
		// Create a well-formed post.
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'draft',
				'post_author'  => $this->user_id,
				'post_content' => str_repeat( 'This is a well-written sentence with good readability. ', 50 ),
				'post_excerpt' => 'Test excerpt',
			)
		);

		// Add category.
		wp_set_post_categories( $post_id, array( $this->factory->category->create() ) );

		// Add featured image.
		$attachment_id = $this->factory->attachment->create_object(
			'image.jpg',
			$post_id,
			array(
				'post_mime_type' => 'image/jpeg',
			)
		);
		set_post_thumbnail( $post_id, $attachment_id );

		$result = $this->command->execute(
			array( $post_id ),
			array( 'publish' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );

		// Check if post was published.
		$post = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status );
	}

	/**
	 * Test finding ready posts automatically
	 */
	public function test_find_ready_posts() {
		// Create draft posts for current user.
		$this->factory->post->create_many(
			3,
			array(
				'post_status' => 'draft',
				'post_author' => $this->user_id,
			)
		);

		// Execute without specifying post IDs.
		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Processed', $result );
	}

	/**
	 * Test no posts ready scenario
	 */
	public function test_no_posts_ready() {
		// No draft posts, should return info message.
		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'No posts ready', $result );
	}

	/**
	 * Test invalid post ID
	 */
	public function test_invalid_post_id() {
		$result = $this->command->execute(
			array( 999999 ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'not found', $result );
	}

	/**
	 * Test readiness score calculation
	 */
	public function test_readiness_score() {
		// Create well-formed post.
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'draft',
				'post_author'  => $this->user_id,
				'post_content' => '<h2>Heading</h2>' . str_repeat( '<p>Good content here. Another sentence. </p>', 30 ),
				'post_excerpt' => 'Excerpt',
			)
		);

		// Add category and featured image.
		wp_set_post_categories( $post_id, array( $this->factory->category->create() ) );
		$attachment_id = $this->factory->attachment->create_object( 'image.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Alt text' );

		$result = $this->command->execute(
			array( $post_id ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Readiness Score', $result );
	}

	/**
	 * Test skip-seo flag
	 */
	public function test_skip_seo_flag() {
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->user_id,
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'dry-run'  => true,
				'skip-seo' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test skip-images flag
	 */
	public function test_skip_images_flag() {
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->user_id,
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'dry-run'     => true,
				'skip-images' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test output format
	 */
	public function test_output_format() {
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->user_id,
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array( 'dry-run' => true ),
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
