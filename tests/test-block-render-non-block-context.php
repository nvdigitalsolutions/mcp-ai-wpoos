<?php
/**
 * Test Block Render Files in Non-Block Context.
 *
 * Tests that block render files handle being included in non-block contexts
 * (e.g., admin pages) without warnings or errors.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for block render files in non-block contexts.
 */
class Test_Block_Render_Non_Block_Context extends WP_UnitTestCase {

	/**
	 * Test tools-grid render.php works in non-block context.
	 */
	public function test_tools_grid_render_non_block_context() {
		// Ensure Tool Registry is available.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Registry' ) );

		// Set up attributes as would be done in non-block context.
		$attributes = array(
			'title'            => 'Test Tools',
			'description'      => 'Test description',
			'showDescriptions' => true,
			'startCollapsed'   => true,
			'showActions'      => true,
			'selectedTools'    => array(),
		);

		// Ensure user has proper permissions.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		// Capture output from including render file.
		ob_start();
		include WP_MCP_AI_PATH . 'includes/blocks/tools-grid/render.php';
		$output = ob_get_clean();

		// Should produce output without errors.
		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'wp-block-wp-mcp-ai-tools-grid', $output );
		$this->assertStringContainsString( 'Test Tools', $output );
	}

	/**
	 * Test knowledge-base render.php works in non-block context.
	 */
	public function test_knowledge_base_render_non_block_context() {
		// Set up attributes as would be done in non-block context.
		$attributes = array(
			'title'         => 'Test Knowledge Base',
			'description'   => 'Test description',
			'allowedTypes'  => '.pdf,.txt,.md',
			'maxFiles'      => 10,
			'maxFileSizeMB' => 10,
			'showPreview'   => true,
		);

		// Ensure user has proper permissions.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		// Capture output from including render file.
		ob_start();
		include WP_MCP_AI_PATH . 'includes/blocks/knowledge-base/render.php';
		$output = ob_get_clean();

		// Should produce output without errors.
		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'wp-block-wp-mcp-ai-knowledge-base', $output );
		$this->assertStringContainsString( 'Test Knowledge Base', $output );
	}

	/**
	 * Test tools-grid render.php handles missing $block variable gracefully.
	 */
	public function test_tools_grid_render_without_block_variable() {
		// Explicitly unset $block to simulate non-block context.
		$block = null;
		unset( $block );

		$attributes = array(
			'title'            => 'Test Tools',
			'description'      => 'Test description',
			'showDescriptions' => true,
			'startCollapsed'   => true,
			'showActions'      => true,
			'selectedTools'    => array(),
		);

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		// This should not produce any warnings or errors.
		ob_start();
		include WP_MCP_AI_PATH . 'includes/blocks/tools-grid/render.php';
		$output = ob_get_clean();

		// Should still produce valid output.
		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'class="wp-block-wp-mcp-ai-tools-grid"', $output );
		$this->assertStringContainsString( 'data-block-id=', $output );
	}

	/**
	 * Test knowledge-base render.php handles missing $block variable gracefully.
	 */
	public function test_knowledge_base_render_without_block_variable() {
		// Explicitly unset $block to simulate non-block context.
		$block = null;
		unset( $block );

		$attributes = array(
			'title'         => 'Test Knowledge Base',
			'description'   => 'Test description',
			'allowedTypes'  => '.pdf,.txt,.md',
			'maxFiles'      => 10,
			'maxFileSizeMB' => 10,
		);

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		// This should not produce any warnings or errors.
		ob_start();
		include WP_MCP_AI_PATH . 'includes/blocks/knowledge-base/render.php';
		$output = ob_get_clean();

		// Should still produce valid output.
		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'class="wp-block-wp-mcp-ai-knowledge-base"', $output );
		$this->assertStringContainsString( 'data-block-id=', $output );
	}

	/**
	 * Test tools-grid render.php denies access for users without permission.
	 */
	public function test_tools_grid_render_permission_check() {
		// Create user without edit_posts capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$attributes = array(
			'title' => 'Test Tools',
		);

		ob_start();
		include WP_MCP_AI_PATH . 'includes/blocks/tools-grid/render.php';
		$output = ob_get_clean();

		// Should show permission denied message.
		$this->assertStringContainsString( 'do not have permission', $output );
	}

	/**
	 * Test knowledge-base render.php denies access for users without permission.
	 */
	public function test_knowledge_base_render_permission_check() {
		// Create user without upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$attributes = array(
			'title' => 'Test Knowledge Base',
		);

		ob_start();
		include WP_MCP_AI_PATH . 'includes/blocks/knowledge-base/render.php';
		$output = ob_get_clean();

		// Should show permission denied message.
		$this->assertStringContainsString( 'do not have permission', $output );
	}
}
