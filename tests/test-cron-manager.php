<?php

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-cron-job.php';

/**
 * Tests for the cron manager utilities.
 */
class WP_MCP_AI_Cron_Manager_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();

        _set_cron_array( array() );
        delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
    }

    public function tearDown(): void {
        _set_cron_array( array() );
        delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

        parent::tearDown();
    }

    public function test_remove_job_unschedules_event() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $hook     = 'wp_mcp_ai_remove_job';
        $future   = time() + HOUR_IN_SECONDS;

        $tool = new WP_MCP_AI_Tool_Create_Cron_Job();
        $tool->execute(
            array(
                'hook'      => $hook,
                'timestamp' => $future,
            ),
            array(
                'user_id' => $admin_id,
            )
        );

        $jobs = WP_MCP_AI_Cron_Manager::get_jobs();
        $this->assertCount( 1, $jobs );

        $job_id = array_key_first( $jobs );
        $this->assertTrue( WP_MCP_AI_Cron_Manager::remove_job( $job_id ) );

        $this->assertSame( array(), WP_MCP_AI_Cron_Manager::get_jobs() );
        $this->assertFalse( wp_next_scheduled( $hook ) );
    }

    public function test_maybe_prune_jobs_removes_missing_events() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $hook     = 'wp_mcp_ai_prune_job';

        $tool = new WP_MCP_AI_Tool_Create_Cron_Job();
        $tool->execute(
            array(
                'hook' => $hook,
            ),
            array(
                'user_id' => $admin_id,
            )
        );

        $jobs = WP_MCP_AI_Cron_Manager::get_jobs();
        $this->assertCount( 1, $jobs );

        _set_cron_array( array() );

        WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

        $this->assertSame( array(), WP_MCP_AI_Cron_Manager::get_jobs() );
    }
}
