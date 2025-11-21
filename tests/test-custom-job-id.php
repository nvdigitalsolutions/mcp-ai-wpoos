<?php
/**
 * Test custom job ID functionality in Cron Manager
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-cron-job.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-cron-job.php';

class WP_MCP_AI_Custom_Job_ID_Test extends WP_UnitTestCase {

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

/**
 * Test that custom job ID is used when provided
 */
public function test_record_job_with_custom_id() {
$hook = 'test_custom_hook';
$args = array( 'test' => 'data' );
$timestamp = time() + 3600;
$custom_id = 'my_custom_job_123';

$job_id = WP_MCP_AI_Cron_Manager::record_job(
$hook,
$args,
'single',
$timestamp,
1,
$custom_id
);

// Verify custom ID was used
$this->assertSame( $custom_id, $job_id );

// Verify job can be retrieved with custom ID
$job = WP_MCP_AI_Cron_Manager::get_job( $custom_id );
$this->assertNotNull( $job );
$this->assertSame( $custom_id, $job['job_id'] );
$this->assertSame( $hook, $job['hook'] );
}

/**
 * Test that MD5 hash is used when no custom ID provided (backward compatibility)
 */
public function test_record_job_without_custom_id() {
$hook = 'test_default_hook';
$args = array( 'test' => 'data' );
$timestamp = time() + 3600;

$job_id = WP_MCP_AI_Cron_Manager::record_job(
$hook,
$args,
'single',
$timestamp,
1
);

// Verify MD5 hash was generated
$this->assertSame( 32, strlen( $job_id ) );
$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $job_id );

// Verify job can be retrieved
$job = WP_MCP_AI_Cron_Manager::get_job( $job_id );
$this->assertNotNull( $job );
$this->assertSame( $hook, $job['hook'] );
}

/**
 * Test that create_cron_job tool returns job_id
 */
public function test_create_cron_job_returns_job_id() {
$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
$hook = 'test_hook_with_id';
$timestamp = time() + 3600;

$tool = new WP_MCP_AI_Tool_Create_Cron_Job();
$result = $tool->execute(
array(
'hook' => $hook,
'timestamp' => $timestamp,
),
array( 'user_id' => $admin_id )
);

$this->assertNotWPError( $result );
$this->assertArrayHasKey( 'job_id', $result );
$this->assertNotEmpty( $result['job_id'] );

// Verify the job_id can be used with get_cron_job
$get_tool = new WP_MCP_AI_Tool_Get_Cron_Job();
$job_details = $get_tool->execute(
array( 'job_id' => $result['job_id'] ),
array( 'user_id' => $admin_id )
);

$this->assertNotWPError( $job_details );
$this->assertSame( $result['job_id'], $job_details['job_id'] );
$this->assertSame( $hook, $job_details['hook'] );
}

/**
 * Test video-like job ID (veo_xxxxx) workflow
 */
public function test_video_job_workflow() {
$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
$custom_job_id = 'veo_' . uniqid( '', true );
$hook = 'wp_mcp_ai_poll_video';
$timestamp = time() + 10;

// Simulate video service recording a job
$returned_id = WP_MCP_AI_Cron_Manager::record_job(
$hook,
array( $custom_job_id ),
'single',
$timestamp,
$admin_id,
$custom_job_id  // Custom ID
);

$this->assertSame( $custom_job_id, $returned_id );

// Simulate AI trying to get the job status
$get_tool = new WP_MCP_AI_Tool_Get_Cron_Job();
$job_details = $get_tool->execute(
array( 'job_id' => $custom_job_id ),
array( 'user_id' => $admin_id )
);

$this->assertNotWPError( $job_details, 'get_cron_job should not return error for veo job' );
$this->assertSame( $custom_job_id, $job_details['job_id'] );
$this->assertSame( $hook, $job_details['hook'] );
}
}
