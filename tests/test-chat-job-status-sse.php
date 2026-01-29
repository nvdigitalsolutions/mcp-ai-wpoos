<?php
/**
 * Tests for Chat Job Status SSE Integration
 *
 * Verifies that job status updates are properly emitted via SSE
 * and can be consumed by chat clients.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Chat Job Status SSE Integration.
 */
class Test_Chat_Job_Status_SSE extends WP_UnitTestCase {

	/**
	 * Admin user ID
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Initialize job notifier.
		if ( class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			WP_MCP_AI_Job_Notifier::init();
		}
	}

	/**
	 * Test that SSE events are emitted when jobs start.
	 */
	public function test_sse_event_emitted_on_job_start() {
		// Define SSE context.
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			define( 'WP_MCP_AI_SSE_ACTIVE', true );
		}

		$event_fired = false;
		$event_name  = '';
		$event_data  = array();

		// Hook into SSE emission action.
		add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $name, $data ) use ( &$event_fired, &$event_name, &$event_data ) {
				$event_fired = true;
				$event_name  = $name;
				$event_data  = $data;
			},
			10,
			2
		);

		// Trigger job started event.
		do_action( 'wp_mcp_ai_job_started', 'test_job_123', array( 'test' => 'metadata' ) );

		// Verify SSE event was emitted.
		$this->assertTrue( $event_fired, 'SSE event should be emitted on job start' );
		$this->assertNotEmpty( $event_name, 'Event name should not be empty' );
		$this->assertIsArray( $event_data, 'Event data should be an array' );
		$this->assertEquals( 'test_job_123', $event_data['job_id'] );
		$this->assertEquals( 'started', $event_data['status'] );
	}

	/**
	 * Test that cron jobs emit cron_job_status_update events.
	 */
	public function test_cron_job_emits_correct_event_type() {
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			define( 'WP_MCP_AI_SSE_ACTIVE', true );
		}

		$event_name = '';

		add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $name ) use ( &$event_name ) {
				$event_name = $name;
			},
			10,
			2
		);

		// Trigger cron job started.
		do_action(
			'wp_mcp_ai_job_started',
			'cron_test_job',
			array( 'tool' => 'create_cron_job' )
		);

		$this->assertEquals(
			'cron_job_status_update',
			$event_name,
			'Cron jobs should emit cron_job_status_update events'
		);
	}

	/**
	 * Test that crawl4ai jobs emit crawl4ai_job_status_update events.
	 */
	public function test_crawl4ai_job_emits_correct_event_type() {
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			define( 'WP_MCP_AI_SSE_ACTIVE', true );
		}

		$event_name = '';

		add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $name ) use ( &$event_name ) {
				$event_name = $name;
			},
			10,
			2
		);

		// Trigger crawl4ai job started.
		do_action(
			'wp_mcp_ai_job_started',
			'crawl4ai_test_job',
			array( 'tool' => 'run_crawl4ai_job' )
		);

		$this->assertEquals(
			'crawl4ai_job_status_update',
			$event_name,
			'Crawl4AI jobs should emit crawl4ai_job_status_update events'
		);
	}

	/**
	 * Test that progress events include progress percentage.
	 */
	public function test_progress_event_includes_percentage() {
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			define( 'WP_MCP_AI_SSE_ACTIVE', true );
		}

		$event_data = array();

		add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $name, $data ) use ( &$event_data ) {
				$event_data = $data;
			},
			10,
			2
		);

		// Trigger progress update.
		do_action( 'wp_mcp_ai_job_progress', 'test_job_123', 75, array( 'message' => 'Processing...' ) );

		$this->assertArrayHasKey( 'progress', $event_data );
		$this->assertEquals( 75, $event_data['progress'] );
		$this->assertArrayHasKey( 'status', $event_data );
		$this->assertArrayHasKey( 'message', $event_data );
	}

	/**
	 * Test that completed events include result data.
	 */
	public function test_completed_event_includes_result() {
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			define( 'WP_MCP_AI_SSE_ACTIVE', true );
		}

		$event_data = array();

		add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $name, $data ) use ( &$event_data ) {
				$event_data = $data;
			},
			10,
			2
		);

		$result = array(
			'success' => true,
			'data'    => array( 'test' => 'result' ),
		);

		// Trigger completion.
		do_action( 'wp_mcp_ai_job_completed', 'test_job_123', $result, array() );

		$this->assertArrayHasKey( 'result', $event_data );
		$this->assertEquals( 'completed', $event_data['status'] );
		$this->assertIsArray( $event_data['result'] );
	}

	/**
	 * Test that failed events include error information.
	 */
	public function test_failed_event_includes_error() {
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			define( 'WP_MCP_AI_SSE_ACTIVE', true );
		}

		$event_data = array();

		add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $name, $data ) use ( &$event_data ) {
				$event_data = $data;
			},
			10,
			2
		);

		$error = new WP_Error( 'test_error', 'Test error message' );

		// Trigger failure.
		do_action( 'wp_mcp_ai_job_failed', 'test_job_123', $error, array() );

		$this->assertArrayHasKey( 'error', $event_data );
		$this->assertEquals( 'failed', $event_data['status'] );
		$this->assertIsArray( $event_data['error'] );
		$this->assertArrayHasKey( 'message', $event_data['error'] );
		$this->assertEquals( 'Test error message', $event_data['error']['message'] );
	}

	/**
	 * Test that events are not emitted outside SSE context.
	 */
	public function test_no_sse_event_outside_sse_context() {
		// Make sure SSE is not active.
		if ( defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			// Can't undefine, so we skip this test in SSE context.
			$this->markTestSkipped( 'Cannot test outside SSE context when SSE is active' );
		}

		$event_fired = false;

		add_action(
			'wp_mcp_ai_emit_sse_event',
			function () use ( &$event_fired ) {
				$event_fired = true;
			}
		);

		// Trigger job event.
		do_action( 'wp_mcp_ai_job_started', 'test_job_123', array() );

		$this->assertFalse( $event_fired, 'SSE events should not be emitted outside SSE context' );
	}

	/**
	 * Test that status messages are generated correctly.
	 */
	public function test_status_messages_generated() {
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			define( 'WP_MCP_AI_SSE_ACTIVE', true );
		}

		$messages = array();

		add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $name, $data ) use ( &$messages ) {
				if ( isset( $data['message'] ) ) {
					$messages[] = $data['message'];
				}
			},
			10,
			2
		);

		// Test started message.
		do_action( 'wp_mcp_ai_job_started', 'test_1', array() );
		$this->assertNotEmpty( $messages[0], 'Started message should be generated' );

		// Test progress message.
		do_action( 'wp_mcp_ai_job_progress', 'test_2', 50, array() );
		$this->assertStringContainsString( '50', $messages[1], 'Progress message should include percentage' );

		// Test completed message.
		do_action( 'wp_mcp_ai_job_completed', 'test_3', array(), array() );
		$this->assertNotEmpty( $messages[2], 'Completed message should be generated' );
	}

	/**
	 * Test that custom messages in metadata are used.
	 */
	public function test_custom_messages_in_metadata() {
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			define( 'WP_MCP_AI_SSE_ACTIVE', true );
		}

		$event_data = array();

		add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $name, $data ) use ( &$event_data ) {
				$event_data = $data;
			},
			10,
			2
		);

		$custom_message = 'Custom progress message';

		// Trigger progress with custom message.
		do_action(
			'wp_mcp_ai_job_progress',
			'test_job',
			25,
			array( 'message' => $custom_message )
		);

		$this->assertEquals(
			$custom_message,
			$event_data['message'],
			'Custom message from metadata should be used'
		);
	}

	/**
	 * Test that metadata is included in event data.
	 */
	public function test_metadata_included_in_event() {
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			define( 'WP_MCP_AI_SSE_ACTIVE', true );
		}

		$event_data = array();

		add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $name, $data ) use ( &$event_data ) {
				$event_data = $data;
			},
			10,
			2
		);

		$metadata = array(
			'tool'    => 'test_tool',
			'user_id' => 123,
			'custom'  => 'data',
		);

		// Trigger job with metadata.
		do_action( 'wp_mcp_ai_job_started', 'test_job', $metadata );

		$this->assertArrayHasKey( 'metadata', $event_data );
		$this->assertEquals( $metadata, $event_data['metadata'] );
	}

	/**
	 * Test that Chat Controller registers SSE event handler.
	 */
	public function test_chat_controller_registers_sse_handler() {
		$this->assertTrue(
			has_action( 'wp_mcp_ai_emit_sse_event' ),
			'Chat Controller should register handler for wp_mcp_ai_emit_sse_event'
		);
	}

	/**
	 * Test progress value normalization (0-100).
	 */
	public function test_progress_value_normalized() {
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			define( 'WP_MCP_AI_SSE_ACTIVE', true );
		}

		$progress_values = array();

		add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $name, $data ) use ( &$progress_values ) {
				if ( isset( $data['progress'] ) ) {
					$progress_values[] = $data['progress'];
				}
			},
			10,
			2
		);

		// Test over 100.
		do_action( 'wp_mcp_ai_job_progress', 'test_1', 150, array() );
		$this->assertEquals( 100, $progress_values[0], 'Progress should be capped at 100' );

		// Test under 0.
		do_action( 'wp_mcp_ai_job_progress', 'test_2', -10, array() );
		$this->assertEquals( 0, $progress_values[1], 'Progress should be floored at 0' );

		// Test valid value.
		do_action( 'wp_mcp_ai_job_progress', 'test_3', 50, array() );
		$this->assertEquals( 50, $progress_values[2], 'Valid progress should be preserved' );
	}
}
