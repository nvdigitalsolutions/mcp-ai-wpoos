<?php
/**
 * Tests for delete_cron_job tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test delete_cron_job tool functionality.
 */
class Test_Tool_Delete_Cron_Job extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Delete_Cron_Job
	 */
	private $tool;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool      = new WP_MCP_AI_Tool_Delete_Cron_Job();
		$this->admin_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'delete_cron_job', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Unauthenticated call returns forbidden.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'job_id' => 'some-job' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Missing job_id returns invalid_job_id error.
	 */
	public function test_missing_job_id_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		// Either cron_disabled (if orchestration disabled) or invalid_job_id.
		$this->assertWPError( $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'wp_mcp_ai_invalid_job_id', 'wp_mcp_ai_cron_disabled' )
		);
	}

	/**
	 * Non-existent job ID returns job_not_found or cron_disabled.
	 */
	public function test_nonexistent_job_returns_error() {
		$result = $this->tool->execute(
			array( 'job_id' => 'nonexistent-job-id-' . uniqid() ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'wp_mcp_ai_job_not_found', 'wp_mcp_ai_cron_disabled' )
		);
	}

	/**
	 * Admin successfully deletes a registered cron job.
	 *
	 * This test registers a job via WP_MCP_AI_Cron_Manager directly and
	 * then confirms the tool deletes it.
	 */
	public function test_admin_deletes_existing_job() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Budget_Enforcement_Service' ) ||
			WP_MCP_AI_Orchestration_Budget_Enforcement_Service::is_cron_orchestration_enabled()
		) {
			// Register a real job and try to delete it.
			$job_id = WP_MCP_AI_Cron_Manager::record_job(
				'wp_mcp_ai_test_hook_' . uniqid(),
				array(),
				'single',
				time() + 3600,
				$this->admin_id
			);

			$result = $this->tool->execute(
				array( 'job_id' => $job_id ),
				array( 'user_id' => $this->admin_id )
			);

			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'message', $result );
			$this->assertSame( $job_id, $result['job_id'] );
		} else {
			$this->markTestSkipped( 'Cron orchestration is disabled in this environment.' );
		}
	}
}
