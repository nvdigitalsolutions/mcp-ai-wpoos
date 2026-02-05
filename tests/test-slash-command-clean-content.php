<?php
/**
 * Test Clean Content Slash Command
 *
 * PHPUnit tests for /clean-content command functionality.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test clean-content command functionality
 */
class Test_Slash_Command_Clean_Content extends WP_UnitTestCase {

	/**
	 * Command instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Clean_Content
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
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-clean-content.php';

		$this->command = new WP_MCP_AI_Slash_Command_Clean_Content();

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
	 * Test Phase 1: Detect Lorem ipsum placeholder text
	 */
	public function test_phase1_detects_lorem_ipsum() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'phase'   => '1',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Lorem ipsum', $result );
	}

	/**
	 * Test Phase 1: Detect draft markers
	 */
	public function test_phase1_detects_draft_markers() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'This is good content. [TODO] Add more information here. [DRAFT]',
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'phase'   => '1',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'draft markers', $result );
	}

	/**
	 * Test Phase 1: Auto-fix functionality
	 */
	public function test_phase1_auto_fix() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Good content here.  [TODO] Fix this.  Multiple  spaces.',
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'phase'    => '1',
				'auto-fix' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Auto-fixed', $result );

		// Verify content was actually fixed.
		$post = get_post( $post_id );
		$this->assertStringNotContainsString( '[TODO]', $post->post_content );
	}

	/**
	 * Test Phase 1: Detect empty HTML tags
	 */
	public function test_phase1_detects_empty_tags() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Good content</p><p></p><div></div><span></span>',
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'phase'   => '1',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'empty HTML tags', $result );
	}

	/**
	 * Test Phase 2: Detect thin content
	 */
	public function test_phase2_detects_thin_content() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Too short.',
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'phase'   => '2',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'thin', $result );
	}

	/**
	 * Test Phase 2: Detect readability issues
	 */
	public function test_phase2_detects_readability_issues() {
		// Create content with very long sentences.
		$long_sentence = str_repeat( 'word ', 50 ) . '. ';
		$post_id       = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => str_repeat( $long_sentence, 5 ),
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'phase'   => '2',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'readability', $result );
	}

	/**
	 * Test Phase 2: Detect missing meta description
	 */
	public function test_phase2_detects_missing_meta() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => str_repeat( 'Good content here. ', 50 ),
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'phase'   => '2',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'meta description', $result );
	}

	/**
	 * Test Phase 3: AI review suggestions
	 */
	public function test_phase3_ai_suggestions() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => str_repeat( 'This is good content. ', 100 ),
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'phase'   => '3',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test all phases together
	 */
	public function test_all_phases() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Short. [TODO] Lorem ipsum.',
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'issue', $result );
	}

	/**
	 * Test limit flag
	 */
	public function test_limit_flag() {
		// Create multiple posts.
		$this->factory->post->create_many(
			10,
			array(
				'post_status' => 'publish',
			)
		);

		$result = $this->command->execute(
			array( 'recent' ),
			array(
				'limit'   => '3',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Checked 3', $result );
	}

	/**
	 * Test no posts scenario
	 */
	public function test_no_posts_to_check() {
		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'No posts', $result );
	}

	/**
	 * Test clean post (no issues)
	 */
	public function test_clean_post() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => str_repeat( '<p>This is clean, well-formatted content with proper structure and length. ', 20 ),
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test certainty grouping in output
	 */
	public function test_certainty_grouping() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '[TODO] Short content. Lorem ipsum.',
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		// Should group issues by certainty level.
		$this->assertStringContainsString( 'High Certainty', $result );
		$this->assertStringContainsString( 'Medium Certainty', $result );
	}

	/**
	 * Test verbose flag
	 */
	public function test_verbose_flag() {
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'dry-run' => true,
				'verbose' => true,
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
		$original_content = '[TODO] Content here.  Multiple  spaces.';
		$post_id          = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => $original_content,
			)
		);

		$result = $this->command->execute(
			array( $post_id ),
			array(
				'dry-run'  => true,
				'auto-fix' => true, // Should be ignored because of dry-run.
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
				'post_status' => 'publish',
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
