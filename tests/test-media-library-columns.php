<?php
/**
 * Tests for Media Library Columns integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for WP_MCP_AI_Admin_Media_Library_Columns class.
 */
class Test_WP_MCP_AI_Admin_Media_Library_Columns extends WP_UnitTestCase {

	/**
	 * Media library columns instance.
	 *
	 * @var WP_MCP_AI_Admin_Media_Library_Columns
	 */
	private $columns;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Mock is_admin for tests.
		set_current_screen( 'upload.php' );

		// Get the columns instance.
		$this->columns = WP_MCP_AI_Admin_Media_Library_Columns::get_instance();
	}

	/**
	 * Test that class is a singleton.
	 */
	public function test_singleton() {
		$instance1 = WP_MCP_AI_Admin_Media_Library_Columns::get_instance();
		$instance2 = WP_MCP_AI_Admin_Media_Library_Columns::get_instance();

		$this->assertSame( $instance1, $instance2, 'Should return the same instance' );
	}

	/**
	 * Test that the column is added to media library.
	 */
	public function test_add_usage_column() {
		$columns = array(
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
			'date'  => 'Date',
		);

		$modified_columns = $this->columns->add_usage_column( $columns );

		$this->assertArrayHasKey( 'wp_mcp_ai_usage', $modified_columns, 'AI Usage column should be added' );
		$this->assertEquals( 'AI Usage', $modified_columns['wp_mcp_ai_usage'], 'Column title should be "AI Usage"' );
	}

	/**
	 * Test get_attachment_usage returns null for attachments without usage.
	 */
	public function test_get_attachment_usage_returns_null_when_no_usage() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create();

		$usage = $this->columns->get_attachment_usage( $attachment_id );

		$this->assertNull( $usage, 'Should return null when no usage data exists' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test get_attachment_usage returns usage data when present.
	 */
	public function test_get_attachment_usage_returns_data() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create();

		// Add usage data.
		$usage_data = array(
			'total_tokens' => 1500,
			'total_cost'   => 0.0023,
			'tool_count'   => 2,
			'last_used'    => '2024-01-15 10:00:00',
		);
		update_post_meta( $attachment_id, '_wp_mcp_ai_usage', $usage_data );

		$usage = $this->columns->get_attachment_usage( $attachment_id );

		$this->assertIsArray( $usage, 'Should return an array' );
		$this->assertEquals( 1500, $usage['total_tokens'], 'Total tokens should match' );
		$this->assertEquals( 0.0023, $usage['total_cost'], 'Total cost should match' );
		$this->assertEquals( 2, $usage['tool_count'], 'Tool count should match' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test that tool tracking extracts attachment ID correctly.
	 */
	public function test_track_attachment_usage_updates_meta() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create();

		// Simulate tool execution result with usage.
		$tool_name = 'generate_image_alt_text';
		$arguments = array( 'attachment_id' => $attachment_id );
		$context   = array( 'user_id' => 1 );
		$result    = array(
			'alt_text' => 'A test image',
			'success'  => true,
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini',
			'usage'    => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
		);

		// Call the tracking method.
		$this->columns->track_attachment_usage( $tool_name, $arguments, $context, $result );

		// Check that meta was updated.
		$usage = get_post_meta( $attachment_id, '_wp_mcp_ai_usage', true );

		$this->assertIsArray( $usage, 'Usage meta should be an array' );
		$this->assertEquals( 150, $usage['total_tokens'], 'Total tokens should be recorded' );
		$this->assertEquals( 1, $usage['tool_count'], 'Tool count should be 1' );
		$this->assertArrayHasKey( 'generate_image_alt_text', $usage['tools'], 'Tool should be in tools list' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test tracking accumulates usage over multiple operations.
	 */
	public function test_track_attachment_usage_accumulates() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create();

		// First operation.
		$this->columns->track_attachment_usage(
			'generate_image_alt_text',
			array( 'attachment_id' => $attachment_id ),
			array( 'user_id' => 1 ),
			array(
				'success'  => true,
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
				'usage'    => array( 'total_tokens' => 100 ),
			)
		);

		// Second operation.
		$this->columns->track_attachment_usage(
			'generate_image_caption',
			array( 'attachment_id' => $attachment_id ),
			array( 'user_id' => 1 ),
			array(
				'success'  => true,
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
				'usage'    => array( 'total_tokens' => 75 ),
			)
		);

		// Check accumulated usage.
		$usage = get_post_meta( $attachment_id, '_wp_mcp_ai_usage', true );

		$this->assertEquals( 175, $usage['total_tokens'], 'Total tokens should be accumulated (100 + 75)' );
		$this->assertEquals( 2, $usage['tool_count'], 'Tool count should be 2' );
		$this->assertArrayHasKey( 'generate_image_alt_text', $usage['tools'] );
		$this->assertArrayHasKey( 'generate_image_caption', $usage['tools'] );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test tracking ignores non-image tools.
	 */
	public function test_track_attachment_usage_ignores_non_image_tools() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create();

		// Call with a non-image tool.
		$this->columns->track_attachment_usage(
			'create_post',
			array( 'attachment_id' => $attachment_id ),
			array( 'user_id' => 1 ),
			array(
				'success' => true,
				'usage'   => array( 'total_tokens' => 100 ),
			)
		);

		// Check that no meta was added.
		$usage = get_post_meta( $attachment_id, '_wp_mcp_ai_usage', true );

		$this->assertEmpty( $usage, 'Usage should not be tracked for non-image tools' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test tracking with result containing attachment_id.
	 */
	public function test_track_attachment_usage_from_result() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create();

		// Simulate image generation where attachment_id is in the result, not arguments.
		$this->columns->track_attachment_usage(
			'generate_openai_image',
			array( 'prompt' => 'A sunset' ),
			array( 'user_id' => 1 ),
			array(
				'success'       => true,
				'attachment_id' => $attachment_id,
				'provider'      => 'openai',
				'model'         => 'dall-e-3',
				'usage'         => array( 'total_tokens' => 500 ),
			)
		);

		// Check that meta was updated.
		$usage = get_post_meta( $attachment_id, '_wp_mcp_ai_usage', true );

		$this->assertIsArray( $usage, 'Usage should be tracked when attachment_id is in result' );
		$this->assertEquals( 500, $usage['total_tokens'], 'Total tokens should be 500' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test render_usage_column outputs nothing for wrong column.
	 */
	public function test_render_usage_column_ignores_wrong_column() {
		$attachment_id = $this->factory->attachment->create();

		ob_start();
		$this->columns->render_usage_column( 'title', $attachment_id );
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Should not output anything for non-AI Usage column' );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test render_usage_column outputs dash when no usage.
	 */
	public function test_render_usage_column_shows_dash_when_no_usage() {
		$attachment_id = $this->factory->attachment->create();

		ob_start();
		$this->columns->render_usage_column( 'wp_mcp_ai_usage', $attachment_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wp-mcp-ai-no-usage', $output, 'Should show no-usage class' );
		$this->assertStringContainsString( '—', $output, 'Should show dash character' );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test render_usage_column outputs badges when usage exists.
	 */
	public function test_render_usage_column_shows_badges() {
		$attachment_id = $this->factory->attachment->create();

		// Add usage data.
		update_post_meta(
			$attachment_id,
			'_wp_mcp_ai_usage',
			array(
				'total_tokens' => 2500,
				'total_cost'   => 0.0050,
				'tool_count'   => 3,
			)
		);

		ob_start();
		$this->columns->render_usage_column( 'wp_mcp_ai_usage', $attachment_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wp-mcp-ai-usage-badges', $output, 'Should have badges container' );
		$this->assertStringContainsString( 'wp-mcp-ai-badge-tokens', $output, 'Should have tokens badge' );
		$this->assertStringContainsString( 'wp-mcp-ai-badge-cost', $output, 'Should have cost badge' );
		$this->assertStringContainsString( 'wp-mcp-ai-badge-tools', $output, 'Should have tools badge' );
		$this->assertStringContainsString( '2.5k tok', $output, 'Should show formatted token count' );
		$this->assertStringContainsString( '3 ops', $output, 'Should show operation count' );

		wp_delete_attachment( $attachment_id, true );
	}
}
