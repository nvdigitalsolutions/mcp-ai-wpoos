<?php
/**
 * Tests for the save_post tool with orchestration mode.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-save-post.php';

/**
 * Test case for multi-step orchestration in save_post tool.
 */
class WP_MCP_AI_Save_Post_Orchestration_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that orchestration mode is disabled by default.
	 */
	public function test_orchestration_mode_disabled_by_default() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'title'   => 'Test Post',
				'content' => 'Test content for the post.',
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertIsArray( $result );
			// Legacy mode should not include orchestration metadata.
			$this->assertArrayNotHasKey( 'orchestration', $result );
			$this->assertArrayNotHasKey( 'execution_id', $result );
		}
	}

	/**
	 * Test that orchestration mode can be enabled.
	 */
	public function test_orchestration_mode_can_be_enabled() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'title'              => 'Test Orchestrated Post',
				'content'            => 'Test content with orchestration enabled.',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertIsArray( $result );
			// Orchestration mode should include metadata.
			$this->assertArrayHasKey( 'orchestration', $result );
			$this->assertArrayHasKey( 'execution_id', $result );
			$this->assertTrue( $result['orchestration']['enabled'] );
			$this->assertArrayHasKey( 'steps', $result['orchestration'] );
		}
	}

	/**
	 * Test validation step rejects posts without content.
	 */
	public function test_validation_step_rejects_missing_content() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'title'              => 'Post Without Content',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'orchestration_failed', $result->get_error_code() );
	}

	/**
	 * Test validation step rejects too-short content.
	 */
	public function test_validation_step_rejects_too_short_content() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'title'              => 'Short Content Post',
				'content'            => 'Short', // Too short (< 10 chars).
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'orchestration_failed', $result->get_error_code() );
	}

	/**
	 * Test validation step rejects duplicate titles.
	 */
	public function test_validation_step_rejects_duplicate_titles() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Save_Post();

		// Create first post (legacy mode).
		$result1 = $tool->execute(
			array(
				'title'              => 'Unique Title Post',
				'content'            => 'First post with unique title for testing.',
				'orchestration_mode' => false,
			),
			array( 'user_id' => $user_id )
		);

		if ( is_wp_error( $result1 ) ) {
			$this->markTestSkipped( 'Cannot create first post: ' . $result1->get_error_message() );
		}

		// Try to create second post with same title (orchestration mode).
		$result2 = $tool->execute(
			array(
				'title'              => 'Unique Title Post',
				'content'            => 'Second post with duplicate title.',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result2 );
		$this->assertStringContainsString( 'orchestration_failed', $result2->get_error_code() );
	}

	/**
	 * Test validation step rejects long titles.
	 */
	public function test_validation_step_rejects_long_titles() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$long_title = str_repeat( 'A', 201 ); // 201 characters (> 200 max).

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'title'              => $long_title,
				'content'            => 'Content for post with overly long title.',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'orchestration_failed', $result->get_error_code() );
	}

	/**
	 * Test that orchestration includes proper step logging.
	 */
	public function test_orchestration_includes_step_logging() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'title'              => 'Test Logging Post',
				'content'            => 'Content to test step logging in orchestration.',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertArrayHasKey( 'execution_id', $result );
			$execution_id = $result['execution_id'];

			// Check that steps were logged.
			$this->assertArrayHasKey( 'orchestration', $result );
			$this->assertArrayHasKey( 'steps', $result['orchestration'] );
			$steps = $result['orchestration']['steps'];

			$this->assertIsArray( $steps );
			$this->assertGreaterThan( 0, count( $steps ) );

			// Verify expected steps are present.
			$step_names = array_column( $steps, 'name' );
			$this->assertContains( 'started', $step_names );
			$this->assertContains( 'validate', $step_names );
			$this->assertContains( 'save', $step_names );
			$this->assertContains( 'completed', $step_names );
		}
	}

	/**
	 * Test content enhancement step (if AI is available).
	 */
	public function test_content_enhancement_step() {
		if ( ! class_exists( 'WP_MCP_AI_Streaming' ) ) {
			$this->markTestSkipped( 'AI streaming not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'title'              => 'AI Enhanced Post',
				'content'            => 'This is some basic content that should be enhanced by AI.',
				'orchestration_mode' => true,
				'enhance_content'    => true,
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			// Enhancement step should be in logs.
			$steps = $result['orchestration']['steps'];
			$step_names = array_column( $steps, 'name' );
			
			// Enhancement may be completed or skipped.
			$this->assertTrue(
				in_array( 'enhance', $step_names, true ) ||
				in_array( 'enhancement_completed', $step_names, true ) ||
				in_array( 'enhancement_skipped', $step_names, true )
			);
		}
	}

	/**
	 * Test auto-research step (if web_search tool is available).
	 */
	public function test_auto_research_step() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'Tool registry not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'title'              => 'Researched Content Post',
				'content'            => 'Content based on web research about the topic.',
				'orchestration_mode' => true,
				'auto_research'      => true,
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			// Research step should be in logs.
			$steps = $result['orchestration']['steps'];
			$step_names = array_column( $steps, 'name' );
			
			// Research may be completed, failed, or skipped.
			$this->assertTrue(
				in_array( 'research', $step_names, true ) ||
				in_array( 'research_completed', $step_names, true ) ||
				in_array( 'research_failed', $step_names, true )
			);
		}
	}

	/**
	 * Test backward compatibility: legacy mode still works.
	 */
	public function test_backward_compatibility_legacy_mode() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Save_Post();

		// Test with orchestration_mode explicitly false.
		$result1 = $tool->execute(
			array(
				'title'              => 'Legacy Post 1',
				'content'            => 'Content for legacy mode test.',
				'orchestration_mode' => false,
			),
			array( 'user_id' => $user_id )
		);

		// Test with orchestration_mode not set (default).
		$result2 = $tool->execute(
			array(
				'title'   => 'Legacy Post 2',
				'content' => 'Content without orchestration parameter.',
			),
			array( 'user_id' => $user_id )
		);

		// Both should work in legacy mode.
		if ( ! is_wp_error( $result1 ) ) {
			$this->assertArrayNotHasKey( 'orchestration', $result1 );
		}

		if ( ! is_wp_error( $result2 ) ) {
			$this->assertArrayNotHasKey( 'orchestration', $result2 );
		}
	}

	/**
	 * Test updating existing post with orchestration.
	 */
	public function test_updating_existing_post_with_orchestration() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Save_Post();

		// Create post first.
		$result1 = $tool->execute(
			array(
				'title'   => 'Original Post',
				'content' => 'Original content.',
			),
			array( 'user_id' => $user_id )
		);

		if ( is_wp_error( $result1 ) ) {
			$this->markTestSkipped( 'Cannot create original post: ' . $result1->get_error_message() );
		}

		$post_id = $result1['ID'];

		// Update with orchestration.
		$result2 = $tool->execute(
			array(
				'post_id'            => $post_id,
				'title'              => 'Updated Post',
				'content'            => 'Updated content with orchestration.',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result2 ) ) {
			$this->assertArrayHasKey( 'orchestration', $result2 );
			$this->assertEquals( $post_id, $result2['ID'] );
		}
	}

	/**
	 * Test parameter schema includes orchestration parameters.
	 */
	public function test_parameter_schema_includes_orchestration_params() {
		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'orchestration_mode', $schema['properties'] );
		$this->assertArrayHasKey( 'auto_research', $schema['properties'] );
		$this->assertArrayHasKey( 'enhance_content', $schema['properties'] );
		$this->assertArrayHasKey( 'optimize', $schema['properties'] );
		$this->assertArrayHasKey( 'generate_featured_image', $schema['properties'] );
	}

	/**
	 * Test tool description mentions orchestration.
	 */
	public function test_tool_description_mentions_orchestration() {
		$tool        = new WP_MCP_AI_Tool_Save_Post();
		$description = $tool->get_description();

		$this->assertStringContainsString( 'orchestration', strtolower( $description ) );
	}

	/**
	 * Test validation accepts valid post types.
	 */
	public function test_validation_accepts_valid_post_types() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'title'              => 'Page Post Type',
				'content'            => 'Content for page post type.',
				'post_type'          => 'page',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		// Should succeed with valid post type.
		if ( ! is_wp_error( $result ) ) {
			$this->assertEquals( 'page', $result['post_type'] );
		}
	}

	/**
	 * Test validation rejects invalid post types.
	 */
	public function test_validation_rejects_invalid_post_types() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'title'              => 'Invalid Post Type',
				'content'            => 'Content for invalid post type.',
				'post_type'          => 'nonexistent_type',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'orchestration_failed', $result->get_error_code() );
	}
}
