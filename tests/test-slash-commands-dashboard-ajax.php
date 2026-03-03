<?php
/**
 * Tests for Slash Commands Dashboard AJAX Endpoints
 *
 * Comprehensive test suite for all AJAX handlers in the Slash Commands Dashboard.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test Slash Commands Dashboard AJAX functionality.
 */
class Test_Slash_Commands_Dashboard_Ajax extends WP_UnitTestCase {

	/**
	 * Dashboard instance
	 *
	 * @var WP_MCP_AI_Admin_Slash_Commands_Dashboard
	 */
	private $dashboard;

	/**
	 * Admin user ID
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Subscriber user ID
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize slash commands system.
		if ( ! function_exists( 'wp_mcp_ai_init_slash_commands' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/slash-commands/slash-commands-init.php';
		}
		wp_mcp_ai_init_slash_commands();

		// Load dashboard class.
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php';
		$this->dashboard = new WP_MCP_AI_Admin_Slash_Commands_Dashboard();

		// Create test users.
		$this->admin_id      = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		$this->editor_id     = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		$this->subscriber_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
	}

	/**
	 * Teardown test environment
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clean up test data.
		delete_option( 'wp_mcp_ai_slash_command_history' );
	}

	/**
	 * Test that AJAX actions are registered
	 */
	public function test_ajax_actions_registered() {
		$actions = array(
			'wp_ajax_wp_mcp_ai_execute_command',
			'wp_ajax_wp_mcp_ai_get_command_history',
			'wp_ajax_wp_mcp_ai_get_history_entry',
			'wp_ajax_wp_mcp_ai_clear_command_history',
			'wp_ajax_wp_mcp_ai_execute_slash_workflow',
		);

		foreach ( $actions as $action ) {
			$this->assertTrue(
				has_action( $action ) !== false,
				"AJAX action {$action} should be registered"
			);
		}
	}

	/**
	 * Test ajax_execute_workflow requires proper nonce
	 */
	public function test_execute_workflow_requires_nonce() {
		wp_set_current_user( $this->admin_id );

		// Setup request without nonce.
		$_POST['workflow'] = 'daily-review';

		// Expect to die with nonce verification error.
		$this->expectException( WPDieException::class );
		$this->dashboard->ajax_execute_workflow();
	}

	/**
	 * Test ajax_execute_workflow requires edit_posts capability
	 */
	public function test_execute_workflow_requires_capability() {
		wp_set_current_user( $this->subscriber_id );

		// Setup request with valid nonce.
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_slash_commands' );
		$_POST['workflow'] = 'daily-review';

		// Capture output.
		ob_start();
		$this->dashboard->ajax_execute_workflow();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Insufficient permissions', $response['data']['message'] );
	}

	/**
	 * Test ajax_execute_workflow validates workflow parameter
	 */
	public function test_execute_workflow_validates_workflow_parameter() {
		wp_set_current_user( $this->editor_id );

		// Setup request with valid nonce but no workflow.
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_slash_commands' );
		$_POST['workflow'] = '';

		// Capture output.
		ob_start();
		$this->dashboard->ajax_execute_workflow();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'No workflow provided', $response['data']['message'] );
	}

	/**
	 * Test ajax_execute_workflow with valid workflow
	 */
	public function test_execute_workflow_with_valid_workflow() {
		wp_set_current_user( $this->editor_id );

		// Setup request with valid nonce and workflow.
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_slash_commands' );
		$_POST['workflow'] = 'daily-review';

		// Capture output.
		ob_start();
		$this->dashboard->ajax_execute_workflow();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( 'output', $response['data'] );
	}

	/**
	 * Test ajax_execute_workflow enforces capability requirements
	 */
	public function test_execute_workflow_enforces_capability_requirements() {
		wp_set_current_user( $this->editor_id );

		// Setup request for site-health workflow (requires manage_options).
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_slash_commands' );
		$_POST['workflow'] = 'site-health';

		// Capture output.
		ob_start();
		$this->dashboard->ajax_execute_workflow();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		// Should fail for editor (doesn't have manage_options).
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permissions', $response['data']['message'] );
		$this->assertStringContainsString( 'manage_options', $response['data']['message'] );
	}

	/**
	 * Test ajax_execute_workflow allows admin to run all workflows
	 */
	public function test_execute_workflow_allows_admin_all_workflows() {
		wp_set_current_user( $this->admin_id );

		// Setup request for site-health workflow.
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_slash_commands' );
		$_POST['workflow'] = 'site-health';

		// Capture output.
		ob_start();
		$this->dashboard->ajax_execute_workflow();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		// Should succeed for admin.
		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( 'output', $response['data'] );
	}

	/**
	 * Test ajax_execute_command requires proper nonce
	 */
	public function test_execute_command_requires_nonce() {
		wp_set_current_user( $this->admin_id );

		// Setup request without nonce.
		$_POST['command'] = '/help';

		// Expect to die with nonce verification error.
		$this->expectException( WPDieException::class );
		$this->dashboard->ajax_execute_command();
	}

	/**
	 * Test ajax_execute_command requires edit_posts capability
	 */
	public function test_execute_command_requires_capability() {
		wp_set_current_user( $this->subscriber_id );

		// Setup request with valid nonce.
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_slash_commands' );
		$_POST['command'] = '/help';

		// Capture output.
		ob_start();
		$this->dashboard->ajax_execute_command();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Insufficient permissions', $response['data']['message'] );
	}

	/**
	 * Test ajax_execute_command validates command parameter
	 */
	public function test_execute_command_validates_command_parameter() {
		wp_set_current_user( $this->editor_id );

		// Setup request with valid nonce but no command.
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_slash_commands' );
		$_POST['command'] = '';

		// Capture output.
		ob_start();
		$this->dashboard->ajax_execute_command();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'No command provided', $response['data']['message'] );
	}

	/**
	 * Test ajax_execute_command with valid command
	 */
	public function test_execute_command_with_valid_command() {
		wp_set_current_user( $this->editor_id );

		// Setup request with valid nonce and command.
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_slash_commands' );
		$_POST['command'] = '/help';

		// Capture output.
		ob_start();
		$this->dashboard->ajax_execute_command();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( 'output', $response['data'] );
	}

	/**
	 * Test ajax_get_history requires proper nonce
	 */
	public function test_get_history_requires_nonce() {
		wp_set_current_user( $this->admin_id );

		// Expect to die with nonce verification error.
		$this->expectException( WPDieException::class );
		$this->dashboard->ajax_get_history();
	}

	/**
	 * Test ajax_get_history requires edit_posts capability
	 */
	public function test_get_history_requires_capability() {
		wp_set_current_user( $this->subscriber_id );

		// Setup request with valid nonce.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_slash_commands' );

		// Capture output.
		ob_start();
		$this->dashboard->ajax_get_history();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Insufficient permissions', $response['data']['message'] );
	}

	/**
	 * Test ajax_get_history returns empty array initially
	 */
	public function test_get_history_returns_empty_initially() {
		wp_set_current_user( $this->editor_id );

		// Setup request with valid nonce.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_slash_commands' );

		// Capture output.
		ob_start();
		$this->dashboard->ajax_get_history();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		$this->assertTrue( $response['success'] );
		$this->assertIsArray( $response['data']['history'] );
		$this->assertEmpty( $response['data']['history'] );
	}

	/**
	 * Test ajax_clear_command_history requires proper nonce
	 */
	public function test_clear_history_requires_nonce() {
		wp_set_current_user( $this->admin_id );

		// Expect to die with nonce verification error.
		$this->expectException( WPDieException::class );
		$this->dashboard->ajax_clear_history();
	}

	/**
	 * Test ajax_clear_command_history requires edit_posts capability
	 */
	public function test_clear_history_requires_capability() {
		wp_set_current_user( $this->subscriber_id );

		// Setup request with valid nonce.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_slash_commands' );

		// Capture output.
		ob_start();
		$this->dashboard->ajax_clear_history();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Insufficient permissions', $response['data']['message'] );
	}

	/**
	 * Test ajax_clear_command_history clears history
	 */
	public function test_clear_history_clears_data() {
		wp_set_current_user( $this->editor_id );

		// Add some history first.
		update_option(
			'wp_mcp_ai_slash_command_history',
			array(
				array(
					'command' => '/help',
					'time'    => time(),
				),
			)
		);

		// Setup request with valid nonce.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_slash_commands' );

		// Capture output.
		ob_start();
		$this->dashboard->ajax_clear_history();
		$output = ob_get_clean();

		$response = json_decode( $output, true );

		$this->assertTrue( $response['success'] );

		// Verify history is cleared.
		$history = get_option( 'wp_mcp_ai_slash_command_history', array() );
		$this->assertEmpty( $history );
	}

	/**
	 * Test log_execution method via reflection
	 */
	public function test_log_execution_creates_history_entry() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'log_execution' );
		$method->setAccessible( true );

		// Log an execution.
		$method->invoke( $this->dashboard, 'command', '/help', 'Help output' );

		// Verify history entry created.
		$history = get_option( 'wp_mcp_ai_slash_command_history', array() );
		$this->assertNotEmpty( $history );
		$this->assertCount( 1, $history );
		$this->assertEquals( 'command', $history[0]['type'] );
		$this->assertEquals( '/help', $history[0]['command'] );
	}

	/**
	 * Test history respects max entries limit
	 */
	public function test_history_respects_max_entries() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'log_execution' );
		$method->setAccessible( true );

		// Get the MAX_HISTORY_ENTRIES constant from the dashboard class.
		$max_entries       = 100; // WP_MCP_AI_Admin_Slash_Commands_Dashboard::MAX_HISTORY_ENTRIES
		$entries_to_create = $max_entries + 5;

		// Log more than MAX_HISTORY_ENTRIES.
		for ( $i = 0; $i < $entries_to_create; $i++ ) {
			$method->invoke( $this->dashboard, 'command', "/help{$i}", "Output {$i}" );
		}

		// Verify only MAX_HISTORY_ENTRIES kept.
		$history = get_option( 'wp_mcp_ai_slash_command_history', array() );
		$this->assertCount( $max_entries, $history );

		// Verify newest entries are kept (oldest removed).
		$last_entry_number = $entries_to_create - 1;
		$this->assertEquals( "/help{$last_entry_number}", $history[0]['command'] );
	}
}
