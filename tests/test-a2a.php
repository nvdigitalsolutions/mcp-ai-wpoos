<?php
/**
 * Tests for the A2A protocol integration.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test A2A protocol components.
 */
class WP_MCP_AI_A2A_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clean up any existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		delete_option( WP_MCP_AI_A2A_Task_Manager::TASKS_OPTION );
		delete_option( WP_MCP_AI_A2A_Push_Notifications::CONFIGS_OPTION );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		delete_option( WP_MCP_AI_A2A_Task_Manager::TASKS_OPTION );
		delete_option( WP_MCP_AI_A2A_Push_Notifications::CONFIGS_OPTION );

		parent::tearDown();
	}

	// ========================================
	// Default Settings Tests
	// ========================================

	/**
	 * Test that A2A settings exist in defaults.
	 */
	public function test_default_settings_include_a2a() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'enable_a2a_server', $defaults );
		$this->assertArrayHasKey( 'a2a_exposed_assistants', $defaults );
		$this->assertArrayHasKey( 'a2a_enable_push_notifications', $defaults );
		$this->assertArrayHasKey( 'enable_a2a_client', $defaults );
		$this->assertArrayHasKey( 'a2a_default_auth_type', $defaults );
		$this->assertArrayHasKey( 'a2a_default_auth_token', $defaults );

		// Check defaults.
		$this->assertFalse( $defaults['enable_a2a_server'] );
		$this->assertFalse( $defaults['a2a_enable_push_notifications'] );
		$this->assertFalse( $defaults['enable_a2a_client'] );
		$this->assertSame( 'none', $defaults['a2a_default_auth_type'] );
		$this->assertSame( '', $defaults['a2a_default_auth_token'] );
	}

	// ========================================
	// Agent Card Tests
	// ========================================

	/**
	 * Test generic site Agent Card has required fields.
	 */
	public function test_agent_card_generic_has_required_fields() {
		$card = WP_MCP_AI_A2A_Agent_Card::build_site_card();

		$this->assertIsArray( $card );
		$this->assertArrayHasKey( 'name', $card );
		$this->assertArrayHasKey( 'description', $card );
		$this->assertArrayHasKey( 'url', $card );
		$this->assertArrayHasKey( 'protocolVersion', $card );
		$this->assertArrayHasKey( 'version', $card );
		$this->assertArrayHasKey( 'capabilities', $card );
		$this->assertArrayHasKey( 'skills', $card );
		$this->assertArrayHasKey( 'defaultInputModes', $card );
		$this->assertArrayHasKey( 'defaultOutputModes', $card );
		$this->assertArrayHasKey( 'securitySchemes', $card );
		$this->assertArrayHasKey( 'security', $card );
		$this->assertArrayHasKey( 'provider', $card );
		$this->assertArrayHasKey( 'supportedInterfaces', $card );
	}

	/**
	 * Test Agent Card protocol version.
	 */
	public function test_agent_card_protocol_version() {
		$card = WP_MCP_AI_A2A_Agent_Card::build_site_card();
		$this->assertSame( '1.0', $card['protocolVersion'] );
	}

	/**
	 * Test Agent Card contains streaming capability.
	 */
	public function test_agent_card_capabilities_include_streaming() {
		$card = WP_MCP_AI_A2A_Agent_Card::build_site_card();
		$this->assertTrue( $card['capabilities']['streaming'] );
	}

	/**
	 * Test Agent Card push notifications defaults to false.
	 */
	public function test_agent_card_push_notifications_default_false() {
		$card = WP_MCP_AI_A2A_Agent_Card::build_site_card();
		$this->assertFalse( $card['capabilities']['pushNotifications'] );
	}

	/**
	 * Test Agent Card push notifications enabled when setting is on.
	 */
	public function test_agent_card_push_notifications_when_enabled() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'a2a_enable_push_notifications' => true )
		);

		$card = WP_MCP_AI_A2A_Agent_Card::build_site_card();
		$this->assertTrue( $card['capabilities']['pushNotifications'] );
	}

	/**
	 * Test Agent Card default input/output modes.
	 */
	public function test_agent_card_default_modes() {
		$card = WP_MCP_AI_A2A_Agent_Card::build_site_card();

		$this->assertContains( 'text/plain', $card['defaultInputModes'] );
		$this->assertContains( 'application/json', $card['defaultInputModes'] );
		$this->assertContains( 'text/plain', $card['defaultOutputModes'] );
		$this->assertContains( 'application/json', $card['defaultOutputModes'] );
	}

	/**
	 * Test Agent Card bearer security scheme always present.
	 */
	public function test_agent_card_bearer_scheme_always_present() {
		$card = WP_MCP_AI_A2A_Agent_Card::build_site_card();
		$this->assertArrayHasKey( 'bearer', $card['securitySchemes'] );
		$this->assertSame( 'http', $card['securitySchemes']['bearer']['type'] );
		$this->assertSame( 'bearer', $card['securitySchemes']['bearer']['scheme'] );
	}

	/**
	 * Test Agent Card includes provider info.
	 */
	public function test_agent_card_provider_info() {
		$card = WP_MCP_AI_A2A_Agent_Card::build_site_card();
		$this->assertArrayHasKey( 'organization', $card['provider'] );
		$this->assertArrayHasKey( 'url', $card['provider'] );
	}

	/**
	 * Test Agent Card supported interfaces.
	 */
	public function test_agent_card_supported_interfaces() {
		$card = WP_MCP_AI_A2A_Agent_Card::build_site_card();
		$this->assertIsArray( $card['supportedInterfaces'] );
		$this->assertNotEmpty( $card['supportedInterfaces'] );
		$this->assertSame( 'JSON-RPC', $card['supportedInterfaces'][0]['protocolBinding'] );
	}

	/**
	 * Test Agent Card for non-existent assistant returns error.
	 */
	public function test_agent_card_invalid_assistant_returns_error() {
		$card = WP_MCP_AI_A2A_Agent_Card::build_card_for_assistant( 99999 );
		$this->assertInstanceOf( 'WP_Error', $card );
		$this->assertSame( 'a2a_invalid_assistant', $card->get_error_code() );
	}

	/**
	 * Test Agent Card filter is applied.
	 */
	public function test_agent_card_filter_applied() {
		$filter_called = false;
		add_filter(
			'wp_mcp_ai_a2a_agent_card',
			function ( $card ) use ( &$filter_called ) {
				$filter_called        = true;
				$card['custom_field'] = 'test_value';
				return $card;
			}
		);

		$card = WP_MCP_AI_A2A_Agent_Card::build_site_card();
		$this->assertTrue( $filter_called );
		$this->assertSame( 'test_value', $card['custom_field'] );
	}

	/**
	 * Test exposed assistants returns default when none configured.
	 */
	public function test_exposed_assistants_returns_default() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'default_assistant' => 42 )
		);

		$exposed = WP_MCP_AI_A2A_Agent_Card::get_exposed_assistants();
		$this->assertContains( 42, $exposed );
	}

	// ========================================
	// Task Manager Tests
	// ========================================

	/**
	 * Test task creation.
	 */
	public function test_task_creation() {
		$message = array(
			'kind'      => 'message',
			'messageId' => 'msg-001',
			'role'      => 'user',
			'parts'     => array(
				array(
					'kind' => 'text',
					'text' => 'Hello, agent!',
				),
			),
		);

		$task = WP_MCP_AI_A2A_Task_Manager::create_task( $message );

		$this->assertIsArray( $task );
		$this->assertSame( 'task', $task['kind'] );
		$this->assertNotEmpty( $task['id'] );
		$this->assertNotEmpty( $task['contextId'] );
		$this->assertSame( WP_MCP_AI_A2A_Task_Manager::STATE_SUBMITTED, $task['status']['state'] );
		$this->assertNotEmpty( $task['status']['timestamp'] );
		$this->assertCount( 1, $task['history'] );
		$this->assertEmpty( $task['artifacts'] );
	}

	/**
	 * Test task creation with custom context ID.
	 */
	public function test_task_creation_with_context_id() {
		$message = array(
			'kind'  => 'message',
			'role'  => 'user',
			'parts' => array(
				array(
					'kind' => 'text',
					'text' => 'test',
				),
			),
		);

		$task = WP_MCP_AI_A2A_Task_Manager::create_task( $message, 'custom-context-123' );
		$this->assertSame( 'custom-context-123', $task['contextId'] );
	}

	/**
	 * Test task state transition from submitted to working.
	 */
	public function test_task_transition_submitted_to_working() {
		$task = $this->create_test_task();

		$updated = WP_MCP_AI_A2A_Task_Manager::transition_state(
			$task['id'],
			WP_MCP_AI_A2A_Task_Manager::STATE_WORKING
		);

		$this->assertIsArray( $updated );
		$this->assertSame( WP_MCP_AI_A2A_Task_Manager::STATE_WORKING, $updated['status']['state'] );
	}

	/**
	 * Test task state transition to completed.
	 */
	public function test_task_transition_working_to_completed() {
		$task = $this->create_test_task();

		WP_MCP_AI_A2A_Task_Manager::transition_state(
			$task['id'],
			WP_MCP_AI_A2A_Task_Manager::STATE_WORKING
		);

		$updated = WP_MCP_AI_A2A_Task_Manager::transition_state(
			$task['id'],
			WP_MCP_AI_A2A_Task_Manager::STATE_COMPLETED
		);

		$this->assertSame( WP_MCP_AI_A2A_Task_Manager::STATE_COMPLETED, $updated['status']['state'] );
	}

	/**
	 * Test invalid state transition is rejected.
	 */
	public function test_invalid_state_transition_rejected() {
		$task = $this->create_test_task();

		// Try to go directly from submitted to input-required (not allowed).
		$result = WP_MCP_AI_A2A_Task_Manager::transition_state(
			$task['id'],
			WP_MCP_AI_A2A_Task_Manager::STATE_INPUT_REQUIRED
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'a2a_invalid_transition', $result->get_error_code() );
	}

	/**
	 * Test transition from terminal state is rejected.
	 */
	public function test_transition_from_terminal_state_rejected() {
		$task = $this->create_test_task();

		WP_MCP_AI_A2A_Task_Manager::transition_state(
			$task['id'],
			WP_MCP_AI_A2A_Task_Manager::STATE_WORKING
		);
		WP_MCP_AI_A2A_Task_Manager::transition_state(
			$task['id'],
			WP_MCP_AI_A2A_Task_Manager::STATE_COMPLETED
		);

		$result = WP_MCP_AI_A2A_Task_Manager::transition_state(
			$task['id'],
			WP_MCP_AI_A2A_Task_Manager::STATE_WORKING
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'a2a_unsupported_operation', $result->get_error_code() );
	}

	/**
	 * Test adding a message to task history.
	 */
	public function test_add_message_to_task() {
		$task = $this->create_test_task();

		$agent_message = array(
			'kind'  => 'message',
			'role'  => 'agent',
			'parts' => array(
				array(
					'kind' => 'text',
					'text' => 'Hello back!',
				),
			),
		);

		$updated = WP_MCP_AI_A2A_Task_Manager::add_message( $task['id'], $agent_message );

		$this->assertIsArray( $updated );
		$this->assertCount( 2, $updated['history'] );
		$this->assertSame( 'agent', $updated['history'][1]['role'] );
	}

	/**
	 * Test adding an artifact to a task.
	 */
	public function test_add_artifact_to_task() {
		$task = $this->create_test_task();

		$artifact = array(
			'artifactId' => 'art-001',
			'name'       => 'report.txt',
			'parts'      => array(
				array(
					'kind' => 'text',
					'text' => 'Report content',
				),
			),
		);

		$updated = WP_MCP_AI_A2A_Task_Manager::add_artifact( $task['id'], $artifact );

		$this->assertIsArray( $updated );
		$this->assertCount( 1, $updated['artifacts'] );
		$this->assertSame( 'art-001', $updated['artifacts'][0]['artifactId'] );
	}

	/**
	 * Test task retrieval.
	 */
	public function test_get_task() {
		$task = $this->create_test_task();

		$retrieved = WP_MCP_AI_A2A_Task_Manager::get_task( $task['id'] );
		$this->assertIsArray( $retrieved );
		$this->assertSame( $task['id'], $retrieved['id'] );
	}

	/**
	 * Test task not found.
	 */
	public function test_get_task_not_found() {
		$result = WP_MCP_AI_A2A_Task_Manager::get_task( 'non-existent-task-id' );
		$this->assertNull( $result );
	}

	/**
	 * Test task cancellation.
	 */
	public function test_cancel_task() {
		$task = $this->create_test_task();

		$result = WP_MCP_AI_A2A_Task_Manager::cancel_task( $task['id'] );
		$this->assertIsArray( $result );
		$this->assertSame( WP_MCP_AI_A2A_Task_Manager::STATE_CANCELED, $result['status']['state'] );
	}

	/**
	 * Test cancel completed task fails.
	 */
	public function test_cancel_completed_task_fails() {
		$task = $this->create_test_task();

		$transition = WP_MCP_AI_A2A_Task_Manager::transition_state( $task['id'], WP_MCP_AI_A2A_Task_Manager::STATE_COMPLETED );
		$this->assertIsArray( $transition );

		$result = WP_MCP_AI_A2A_Task_Manager::cancel_task( $task['id'] );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'a2a_task_not_cancelable', $result->get_error_code() );
	}

	/**
	 * Test task list.
	 */
	public function test_list_tasks() {
		$this->create_test_task();
		$this->create_test_task();
		$this->create_test_task();

		$list = WP_MCP_AI_A2A_Task_Manager::list_tasks();
		$this->assertArrayHasKey( 'tasks', $list );
		$this->assertArrayHasKey( 'nextPageToken', $list );
		$this->assertCount( 3, $list['tasks'] );
	}

	/**
	 * Test task list pagination.
	 */
	public function test_list_tasks_pagination() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_test_task();
		}

		$page1 = WP_MCP_AI_A2A_Task_Manager::list_tasks( array( 'per_page' => 2 ) );
		$this->assertCount( 2, $page1['tasks'] );
		$this->assertNotEmpty( $page1['nextPageToken'] );

		$page2 = WP_MCP_AI_A2A_Task_Manager::list_tasks(
			array(
				'per_page'   => 2,
				'page_token' => $page1['nextPageToken'],
			)
		);
		$this->assertCount( 2, $page2['tasks'] );
	}

	/**
	 * Test task list filtering by state.
	 */
	public function test_list_tasks_filter_by_state() {
		$task1 = $this->create_test_task();
		$task2 = $this->create_test_task();

		WP_MCP_AI_A2A_Task_Manager::transition_state( $task1['id'], WP_MCP_AI_A2A_Task_Manager::STATE_COMPLETED );

		$completed = WP_MCP_AI_A2A_Task_Manager::list_tasks(
			array( 'state' => WP_MCP_AI_A2A_Task_Manager::STATE_COMPLETED )
		);
		$this->assertCount( 1, $completed['tasks'] );
		$this->assertSame( $task1['id'], $completed['tasks'][0]['id'] );
	}

	/**
	 * Test is_terminal_state helper.
	 */
	public function test_is_terminal_state() {
		$this->assertTrue( WP_MCP_AI_A2A_Task_Manager::is_terminal_state( 'completed' ) );
		$this->assertTrue( WP_MCP_AI_A2A_Task_Manager::is_terminal_state( 'failed' ) );
		$this->assertTrue( WP_MCP_AI_A2A_Task_Manager::is_terminal_state( 'canceled' ) );
		$this->assertTrue( WP_MCP_AI_A2A_Task_Manager::is_terminal_state( 'rejected' ) );
		$this->assertFalse( WP_MCP_AI_A2A_Task_Manager::is_terminal_state( 'submitted' ) );
		$this->assertFalse( WP_MCP_AI_A2A_Task_Manager::is_terminal_state( 'working' ) );
		$this->assertFalse( WP_MCP_AI_A2A_Task_Manager::is_terminal_state( 'input-required' ) );
	}

	/**
	 * Test task state change action fires.
	 */
	public function test_task_state_change_action_fires() {
		$action_fired = false;
		add_action(
			'wp_mcp_ai_a2a_task_state_change',
			function ( $task, $old_state, $new_state ) use ( &$action_fired ) {
				$action_fired = true;
			},
			10,
			3
		);

		$task = $this->create_test_task();
		WP_MCP_AI_A2A_Task_Manager::transition_state( $task['id'], WP_MCP_AI_A2A_Task_Manager::STATE_WORKING );
		$this->assertTrue( $action_fired );
	}

	// ========================================
	// Message Translator Tests
	// ========================================

	/**
	 * Test A2A to chat translation.
	 */
	public function test_a2a_to_chat_translation() {
		$a2a_request = array(
			'message' => array(
				'kind'      => 'message',
				'messageId' => 'msg-001',
				'role'      => 'user',
				'contextId' => 'ctx-001',
				'parts'     => array(
					array(
						'kind' => 'text',
						'text' => 'What is the weather?',
					),
				),
			),
		);

		$result = WP_MCP_AI_A2A_Message_Translator::a2a_to_chat( $a2a_request );

		$this->assertArrayHasKey( 'messages', $result );
		$this->assertArrayHasKey( 'context_id', $result );
		$this->assertSame( 'ctx-001', $result['context_id'] );
		$this->assertCount( 1, $result['messages'] );
		$this->assertSame( 'user', $result['messages'][0]['role'] );
		$this->assertStringContainsString( 'weather', $result['messages'][0]['content'] );
	}

	/**
	 * Test chat to A2A message translation.
	 */
	public function test_chat_to_a2a_message() {
		$message = WP_MCP_AI_A2A_Message_Translator::chat_to_a2a_message(
			'The weather is sunny.',
			'ctx-001'
		);

		$this->assertSame( 'message', $message['kind'] );
		$this->assertSame( 'agent', $message['role'] );
		$this->assertNotEmpty( $message['messageId'] );
		$this->assertSame( 'ctx-001', $message['contextId'] );
		$this->assertCount( 1, $message['parts'] );
		$this->assertSame( 'text', $message['parts'][0]['kind'] );
		$this->assertSame( 'The weather is sunny.', $message['parts'][0]['text'] );
	}

	/**
	 * Test chat to A2A artifact.
	 */
	public function test_chat_to_a2a_artifact() {
		$artifact = WP_MCP_AI_A2A_Message_Translator::chat_to_a2a_artifact(
			'Report content',
			'report.txt'
		);

		$this->assertNotEmpty( $artifact['artifactId'] );
		$this->assertSame( 'report.txt', $artifact['name'] );
		$this->assertSame( 'Report content', $artifact['parts'][0]['text'] );
	}

	/**
	 * Test tool result to artifact conversion.
	 */
	public function test_tool_result_to_artifact() {
		$tool_result = array(
			'status' => 'success',
			'data'   => array( 'temperature' => 72 ),
		);

		$artifact = WP_MCP_AI_A2A_Message_Translator::tool_result_to_artifact( $tool_result, 'weather_tool' );

		$this->assertNotEmpty( $artifact['artifactId'] );
		$this->assertStringContainsString( 'weather_tool', $artifact['name'] );
	}

	/**
	 * Test status update builder.
	 */
	public function test_build_status_update() {
		$update = WP_MCP_AI_A2A_Message_Translator::build_status_update(
			'task-001',
			'ctx-001',
			'working'
		);

		$this->assertSame( 'status-update', $update['kind'] );
		$this->assertSame( 'task-001', $update['taskId'] );
		$this->assertSame( 'ctx-001', $update['contextId'] );
		$this->assertSame( 'working', $update['status']['state'] );
		$this->assertFalse( $update['final'] );
	}

	/**
	 * Test status update with final flag.
	 */
	public function test_build_status_update_final() {
		$update = WP_MCP_AI_A2A_Message_Translator::build_status_update(
			'task-001',
			'ctx-001',
			'completed',
			null,
			true
		);

		$this->assertTrue( $update['final'] );
	}

	/**
	 * Test role mapping from A2A to chat.
	 */
	public function test_role_mapping_a2a_to_chat() {
		$agent_request = array(
			'message' => array(
				'role'  => 'agent',
				'parts' => array(
					array(
						'kind' => 'text',
						'text' => 'test',
					),
				),
			),
		);

		$result = WP_MCP_AI_A2A_Message_Translator::a2a_to_chat( $agent_request );
		$this->assertSame( 'assistant', $result['messages'][0]['role'] );
	}

	/**
	 * Test role mapping from chat to A2A.
	 */
	public function test_role_mapping_chat_to_a2a() {
		$this->assertSame( 'user', WP_MCP_AI_A2A_Message_Translator::map_chat_role_to_a2a( 'user' ) );
		$this->assertSame( 'agent', WP_MCP_AI_A2A_Message_Translator::map_chat_role_to_a2a( 'assistant' ) );
		$this->assertSame( 'agent', WP_MCP_AI_A2A_Message_Translator::map_chat_role_to_a2a( 'system' ) );
	}

	// ========================================
	// Push Notification Tests
	// ========================================

	/**
	 * Test push notification config creation.
	 */
	public function test_push_config_creation() {
		$task = $this->create_test_task();

		$config = WP_MCP_AI_A2A_Push_Notifications::create_config(
			$task['id'],
			array( 'url' => 'https://example.com/webhook' )
		);

		$this->assertIsArray( $config );
		$this->assertNotEmpty( $config['id'] );
		$this->assertSame( $task['id'], $config['taskId'] );
		$this->assertSame( 'https://example.com/webhook', $config['url'] );
	}

	/**
	 * Test push notification config retrieval.
	 */
	public function test_push_config_retrieval() {
		$task   = $this->create_test_task();
		$config = WP_MCP_AI_A2A_Push_Notifications::create_config(
			$task['id'],
			array( 'url' => 'https://example.com/webhook' )
		);

		$retrieved = WP_MCP_AI_A2A_Push_Notifications::get_config( $task['id'], $config['id'] );
		$this->assertIsArray( $retrieved );
		$this->assertSame( $config['id'], $retrieved['id'] );
	}

	/**
	 * Test push notification config listing.
	 */
	public function test_push_config_listing() {
		$task = $this->create_test_task();

		WP_MCP_AI_A2A_Push_Notifications::create_config(
			$task['id'],
			array( 'url' => 'https://example.com/webhook1' )
		);
		WP_MCP_AI_A2A_Push_Notifications::create_config(
			$task['id'],
			array( 'url' => 'https://example.com/webhook2' )
		);

		$list = WP_MCP_AI_A2A_Push_Notifications::list_configs( $task['id'] );
		$this->assertCount( 2, $list );
	}

	/**
	 * Test push notification config deletion.
	 */
	public function test_push_config_deletion() {
		$task   = $this->create_test_task();
		$config = WP_MCP_AI_A2A_Push_Notifications::create_config(
			$task['id'],
			array( 'url' => 'https://example.com/webhook' )
		);

		$result = WP_MCP_AI_A2A_Push_Notifications::delete_config( $task['id'], $config['id'] );
		$this->assertTrue( $result['deleted'] );

		$list = WP_MCP_AI_A2A_Push_Notifications::list_configs( $task['id'] );
		$this->assertCount( 0, $list );
	}

	/**
	 * Test push notification config not found.
	 */
	public function test_push_config_not_found() {
		$result = WP_MCP_AI_A2A_Push_Notifications::get_config( 'no-task', 'no-config' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	// ========================================
	// Webhook Handler Tests
	// ========================================

	/**
	 * Test webhook handler processes task update.
	 */
	public function test_webhook_handler_task_update() {
		$action_fired = false;
		add_action(
			'wp_mcp_ai_a2a_webhook_task_update',
			function () use ( &$action_fired ) {
				$action_fired = true;
			}
		);

		$payload = array(
			'task' => array(
				'id'     => 'task-001',
				'status' => array( 'state' => 'completed' ),
			),
		);

		$result = WP_MCP_AI_A2A_Webhook_Handler::handle_inbound( $payload );
		$this->assertTrue( $result );
		$this->assertTrue( $action_fired );
	}

	/**
	 * Test webhook handler rejects invalid payload.
	 */
	public function test_webhook_handler_rejects_invalid_payload() {
		$result = WP_MCP_AI_A2A_Webhook_Handler::handle_inbound( 'not-an-array' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test webhook handler rejects unknown event type.
	 */
	public function test_webhook_handler_rejects_unknown_event() {
		$result = WP_MCP_AI_A2A_Webhook_Handler::handle_inbound( array( 'unknown' => 'data' ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'a2a_unknown_event', $result->get_error_code() );
	}

	// ========================================
	// Delegate Tool Tests
	// ========================================

	/**
	 * Test delegate tool has correct slug.
	 */
	public function test_delegate_tool_slug() {
		$tool = new WP_MCP_AI_Tool_Delegate_To_A2A_Agent();
		$this->assertSame( 'delegate_to_a2a_agent', $tool->get_slug() );
	}

	/**
	 * Test delegate tool has parameters schema.
	 */
	public function test_delegate_tool_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Delegate_To_A2A_Agent();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'agent_url', $schema['properties'] );
		$this->assertArrayHasKey( 'task_description', $schema['properties'] );
		$this->assertContains( 'agent_url', $schema['required'] );
		$this->assertContains( 'task_description', $schema['required'] );
	}

	/**
	 * Test delegate tool requires agent_url.
	 */
	public function test_delegate_tool_requires_agent_url() {
		$tool   = new WP_MCP_AI_Tool_Delegate_To_A2A_Agent();
		$result = $tool->execute(
			array(
				'task_description' => 'Do something',
			)
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Agent URL', $result->get_error_message() );
	}

	/**
	 * Test delegate tool requires task_description.
	 */
	public function test_delegate_tool_requires_task_description() {
		$tool   = new WP_MCP_AI_Tool_Delegate_To_A2A_Agent();
		$result = $tool->execute(
			array(
				'agent_url' => 'https://example.com',
			)
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Task description', $result->get_error_message() );
	}

	// ========================================
	// A2A Client Tests
	// ========================================

	/**
	 * Test has_capability check.
	 */
	public function test_client_has_capability() {
		$card = array(
			'capabilities' => array(
				'streaming'         => true,
				'pushNotifications' => false,
			),
		);

		$this->assertTrue( WP_MCP_AI_A2A_Client::has_capability( $card, 'streaming' ) );
		$this->assertFalse( WP_MCP_AI_A2A_Client::has_capability( $card, 'pushNotifications' ) );
		$this->assertFalse( WP_MCP_AI_A2A_Client::has_capability( $card, 'nonexistent' ) );
	}

	/**
	 * Test find_skill by name.
	 */
	public function test_client_find_skill_by_name() {
		$card = array(
			'skills' => array(
				array(
					'id'   => 'weather',
					'name' => 'Weather Lookup',
					'tags' => array( 'tool' ),
				),
				array(
					'id'   => 'search',
					'name' => 'Web Search',
					'tags' => array( 'tool' ),
				),
			),
		);

		$found = WP_MCP_AI_A2A_Client::find_skill( $card, 'weather' );
		$this->assertNotNull( $found );
		$this->assertSame( 'weather', $found['id'] );

		$not_found = WP_MCP_AI_A2A_Client::find_skill( $card, 'nonexistent' );
		$this->assertNull( $not_found );
	}

	/**
	 * Test find_skill by tag.
	 */
	public function test_client_find_skill_by_tag() {
		$card = array(
			'skills' => array(
				array(
					'id'   => 'planner',
					'name' => 'Task Planner',
					'tags' => array( 'role', 'planner' ),
				),
			),
		);

		$found = WP_MCP_AI_A2A_Client::find_skill( $card, 'planner' );
		$this->assertNotNull( $found );
		$this->assertSame( 'planner', $found['id'] );
	}

	// ========================================
	// A2A Settings Section Tests
	// ========================================

	/**
	 * Test A2A section has correct ID.
	 */
	public function test_a2a_section_id() {
		$section = new WP_MCP_AI_Section_A2A();
		$this->assertSame( 'a2a', $section->get_id() );
	}

	/**
	 * Test A2A section has correct tab.
	 */
	public function test_a2a_section_tab() {
		$section = new WP_MCP_AI_Section_A2A();
		$this->assertSame( 'a2a', $section->get_tab() );
	}

	/**
	 * Test A2A section has fields.
	 */
	public function test_a2a_section_has_fields() {
		$section = new WP_MCP_AI_Section_A2A();
		$fields  = $section->get_fields();

		$this->assertIsArray( $fields );
		$this->assertArrayHasKey( 'enable_a2a_server', $fields );
		$this->assertArrayHasKey( 'a2a_exposed_assistants', $fields );
		$this->assertArrayHasKey( 'a2a_enable_push_notifications', $fields );
		$this->assertArrayHasKey( 'enable_a2a_client', $fields );
		$this->assertArrayHasKey( 'a2a_default_auth_type', $fields );
		$this->assertArrayHasKey( 'a2a_default_auth_token', $fields );
	}

	// ========================================
	// Helper Methods
	// ========================================

	/**
	 * Create a test task.
	 *
	 * @return array The created task.
	 */
	protected function create_test_task() {
		$message = array(
			'kind'  => 'message',
			'role'  => 'user',
			'parts' => array(
				array(
					'kind' => 'text',
					'text' => 'Test message',
				),
			),
		);

		return WP_MCP_AI_A2A_Task_Manager::create_task( $message );
	}
}
