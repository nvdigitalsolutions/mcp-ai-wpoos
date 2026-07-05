<?php
/**
 * Tests for generate_omni_video tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test generate_omni_video tool functionality.
 *
 * @group external-http
 */
class Test_Tool_Generate_Omni_Video extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Omni_Video
	 */
	private $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_Omni_Video' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Generate_Omni_Video class not available.' );
		}

		$this->tool = new WP_MCP_AI_Tool_Generate_Omni_Video();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'generate_omni_video', $this->tool->get_slug() );
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
	 * Parameter schema is valid and requires prompt.
	 */
	public function test_parameter_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'prompt', $schema['required'] );

		// Verify key properties exist.
		$this->assertArrayHasKey( 'duration', $schema['properties'] );
		$this->assertArrayHasKey( 'aspect_ratio', $schema['properties'] );
		$this->assertArrayHasKey( 'resolution', $schema['properties'] );
		$this->assertArrayHasKey( 'reference_images', $schema['properties'] );
		$this->assertArrayHasKey( 'async', $schema['properties'] );
	}

	/**
	 * Missing prompt returns error.
	 */
	public function test_missing_prompt_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Empty prompt returns error.
	 */
	public function test_empty_prompt_returns_error() {
		$result = $this->tool->execute(
			array( 'prompt' => '' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * 1080p with non-16:9 aspect ratio returns error.
	 */
	public function test_1080p_without_16_9_returns_error() {
		$result = $this->tool->execute(
			array(
				'prompt'      => 'A sunset beach video.',
				'resolution'  => '1080p',
				'aspect_ratio' => '1:1',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_resolution', $result->get_error_code() );
	}

	/**
	 * Duration below minimum is clamped to 4.
	 */
	public function test_duration_below_minimum_clamped() {
		// Should not error on invalid duration — it gets clamped.
		$result = $this->tool->execute(
			array(
				'prompt'   => 'A test video.',
				'duration' => 1,
			),
			array( 'user_id' => $this->admin_id )
		);

		// May succeed or fail depending on Omni availability,
		// but should not be a duration validation error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame( 'wp_mcp_ai_invalid_duration', $result->get_error_code() );
		}
	}

	/**
	 * Invalid aspect ratio is silently corrected to default.
	 */
	public function test_invalid_aspect_ratio_silently_corrected() {
		$result = $this->tool->execute(
			array(
				'prompt'       => 'A test video.',
				'aspect_ratio' => 'invalid',
			),
			array( 'user_id' => $this->admin_id )
		);

		// Should not produce a validation error for aspect ratio.
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame( 'wp_mcp_ai_invalid_aspect_ratio', $result->get_error_code() );
		}
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
		$this->assertArrayHasKey( 'token_multiplier', $flags );
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
