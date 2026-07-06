<?php
/**
 * Tests for edit_omni_video tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test edit_omni_video tool functionality.
 *
 * @group external-http
 */
class Test_Tool_Edit_Omni_Video extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Edit_Omni_Video
	 */
	private $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Edit_Omni_Video' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Edit_Omni_Video class not available.' );
		}

		$this->tool = new WP_MCP_AI_Tool_Edit_Omni_Video();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'edit_omni_video', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Required capability is edit_posts.
	 */
	public function test_required_capability() {
		$this->assertSame( 'edit_posts', $this->tool->get_required_capability() );
	}

	/**
	 * Parameter schema is valid and requires edit_prompt.
	 */
	public function test_parameter_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'edit_prompt', $schema['required'] );

		// Verify key properties exist.
		$this->assertArrayHasKey( 'source_video_id', $schema['properties'] );
		$this->assertArrayHasKey( 'previous_video_id', $schema['properties'] );
		$this->assertArrayHasKey( 'aspect_ratio', $schema['properties'] );
		$this->assertArrayHasKey( 'async', $schema['properties'] );
	}

	/**
	 * Missing edit_prompt returns error.
	 */
	public function test_missing_edit_prompt_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_edit_prompt', $result->get_error_code() );
	}

	/**
	 * Empty edit_prompt returns error.
	 */
	public function test_empty_edit_prompt_returns_error() {
		$result = $this->tool->execute(
			array( 'edit_prompt' => '' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_edit_prompt', $result->get_error_code() );
	}

	/**
	 * Non-existent source video returns error.
	 */
	public function test_nonexistent_source_video_returns_error() {
		$result = $this->tool->execute(
			array(
				'edit_prompt'     => 'Change the background to sunset.',
				'source_video_id' => 999999,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_source_not_found', $result->get_error_code() );
	}

	/**
	 * Missing both source_video_id and previous_video_id returns error.
	 */
	public function test_missing_all_source_params_returns_error() {
		$result = $this->tool->execute(
			array( 'edit_prompt' => 'Stabilize footage.' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_source', $result->get_error_code() );
	}

	/**
	 * Non-video attachment returns error.
	 */
	public function test_non_video_attachment_returns_error() {
		// Create a non-video attachment (a simple text file).
		$filename = wp_tempnam( 'test-file.txt' );
		file_put_contents( $filename, 'test content' );

		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => $filename,
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Test Text File',
			)
		);

		$result = $this->tool->execute(
			array(
				'edit_prompt'     => 'Change background.',
				'source_video_id' => $attachment_id,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_source', $result->get_error_code() );

		unlink( $filename );
	}

	/**
	 * Capability flags include background-only.
	 */
	public function test_capability_flags() {
		if ( ! method_exists( $this->tool, 'get_capability_flags' ) ) {
			$this->markTestSkipped( 'get_capability_flags method not available.' );
		}

		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertArrayHasKey( 'background-only', $flags );
		$this->assertTrue( $flags['background-only'] );
	}

	/**
	 * Model requirements specify gemini provider.
	 */
	public function test_model_requirements() {
		if ( ! method_exists( $this->tool, 'get_model_requirements' ) ) {
			$this->markTestSkipped( 'get_model_requirements method not available.' );
		}

		$requirements = $this->tool->get_model_requirements();

		$this->assertIsArray( $requirements );
		$this->assertArrayHasKey( 'providers', $requirements );
		$this->assertContains( 'gemini', $requirements['providers'] );
	}

	/**
	 * Async metadata specifies background-only and timeout.
	 */
	public function test_async_metadata() {
		if ( ! method_exists( $this->tool, 'get_async_metadata' ) ) {
			$this->markTestSkipped( 'get_async_metadata method not available.' );
		}

		$metadata = $this->tool->get_async_metadata();

		$this->assertIsArray( $metadata );
		$this->assertArrayHasKey( 'background-only', $metadata );
		$this->assertTrue( $metadata['background-only'] );
		$this->assertArrayHasKey( 'timeout', $metadata );
	}
}
