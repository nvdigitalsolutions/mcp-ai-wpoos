<?php
/**
 * A2A ported-class tests.
 *
 * Verifies the extraction port (src/A2A/) preserves the public behaviour of
 * the base plugin's A2A subsystem (mcp-ai-wpoos/includes/a2a/).
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\A2A\AgentCard;
use NvoosContentGraphAiPlatform\A2A\Client;
use NvoosContentGraphAiPlatform\A2A\MessageTranslator;
use NvoosContentGraphAiPlatform\A2A\TaskManager;
use NvoosContentGraphAiPlatform\A2A\WebhookHandler;

/**
 * @group a2a
 */
class Test_Platform_A2A extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( TaskManager::TASKS_OPTION );
	}

	public function test_registry_resolution_never_fatals_in_standalone_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Standalone-only check: the base plugin owns its registry in monolith mode.' );
		}

		// The monorepo root autoloader can classmap WP_MCP_AI_Tool_Registry
		// to disk even when the base plugin is inactive — resolving it must
		// not touch the base files (they reference WP_MCP_AI_PATH).
		$registry = $this->invoke_protected_static( AgentCard::class, 'resolve_tool_registry' );

		$this->assertTrue( null === $registry || is_object( $registry ) );
	}

	/**
	 * Invoke a protected static method on a class for contract testing.
	 *
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return mixed Method result.
	 */
	private function invoke_protected_static( $class_name, $method ) {
		$reflection = new \ReflectionMethod( $class_name, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null );
	}

	public function test_create_task_initializes_submitted_state(): void {
		$task = TaskManager::create_task( array( 'kind' => 'message' ) );

		$this->assertArrayHasKey( 'id', $task );
		$this->assertSame( 'task', $task['kind'] );
		$this->assertSame( TaskManager::STATE_SUBMITTED, $task['status']['state'] );
		$this->assertNotEmpty( $task['contextId'] );
		$this->assertNotEmpty( $task['status']['timestamp'] );
		$this->assertSame( TaskManager::get_task( $task['id'] )['id'], $task['id'] );
	}

	public function test_transition_state_valid_and_invalid(): void {
		$task = TaskManager::create_task( array( 'kind' => 'message' ) );

		$updated = TaskManager::transition_state( $task['id'], TaskManager::STATE_WORKING );
		$this->assertSame( TaskManager::STATE_WORKING, $updated['status']['state'] );

		// working -> rejected is not in the valid transition map.
		$invalid = TaskManager::transition_state( $task['id'], TaskManager::STATE_REJECTED );
		$this->assertWPError( $invalid );
		$this->assertSame( 'a2a_invalid_transition', $invalid->get_error_code() );
	}

	public function test_terminal_state_blocks_transition(): void {
		$task = TaskManager::create_task( array( 'kind' => 'message' ) );

		TaskManager::transition_state( $task['id'], TaskManager::STATE_COMPLETED );
		$result = TaskManager::transition_state( $task['id'], TaskManager::STATE_WORKING );
		$this->assertWPError( $result );
		$this->assertSame( 'a2a_unsupported_operation', $result->get_error_code() );
	}

	public function test_cancel_task(): void {
		$task = TaskManager::create_task( array( 'kind' => 'message' ) );

		$canceled = TaskManager::cancel_task( $task['id'] );
		$this->assertSame( TaskManager::STATE_CANCELED, $canceled['status']['state'] );
		$this->assertTrue( TaskManager::is_terminal_state( TaskManager::STATE_CANCELED ) );
	}

	public function test_cancel_terminal_task_fails(): void {
		$task = TaskManager::create_task( array( 'kind' => 'message' ) );

		TaskManager::transition_state( $task['id'], TaskManager::STATE_COMPLETED );
		$result = TaskManager::cancel_task( $task['id'] );
		$this->assertWPError( $result );
		$this->assertSame( 'a2a_task_not_cancelable', $result->get_error_code() );
	}

	public function test_missing_task_errors(): void {
		$this->assertWPError( TaskManager::transition_state( 'missing', TaskManager::STATE_WORKING ) );
		$this->assertWPError( TaskManager::add_message( 'missing', array( 'kind' => 'message' ) ) );
		$this->assertWPError( TaskManager::add_artifact( 'missing', array( 'kind' => 'artifact' ) ) );
		$this->assertNull( TaskManager::get_task( 'missing' ) );
	}

	public function test_list_tasks_filters_and_strips_artifacts(): void {
		$task_a = TaskManager::create_task( array( 'kind' => 'message' ), 'ctx-a' );
		TaskManager::add_artifact( $task_a['id'], array( 'kind' => 'artifact' ) );

		$listing = TaskManager::list_tasks(
			array(
				'context_id' => 'ctx-a',
				'per_page'   => 10,
			)
		);
		$this->assertCount( 1, $listing['tasks'] );
		$this->assertArrayNotHasKey( 'artifacts', $listing['tasks'][0] );

		$with_artifacts = TaskManager::list_tasks(
			array(
				'context_id'        => 'ctx-a',
				'include_artifacts' => true,
			)
		);
		$this->assertArrayHasKey( 'artifacts', $with_artifacts['tasks'][0] );

		$empty = TaskManager::list_tasks( array( 'context_id' => 'no-match' ) );
		$this->assertCount( 0, $empty['tasks'] );
	}

	public function test_message_translator_a2a_to_chat(): void {
		$result = MessageTranslator::a2a_to_chat(
			array(
				'message' => array(
					'role'      => 'user',
					'contextId' => 'ctx-1',
					'parts'     => array(
						array(
							'kind' => 'text',
							'text' => 'Hello',
						),
					),
				),
			)
		);

		$this->assertSame( 'user', $result['messages'][0]['role'] );
		$this->assertSame( 'Hello', $result['messages'][0]['content'] );
		$this->assertSame( 'ctx-1', $result['context_id'] );
	}

	public function test_message_translator_chat_to_a2a_message(): void {
		$message = MessageTranslator::chat_to_a2a_message( 'Reply', 'ctx-2', array( 'k' => 'v' ) );

		$this->assertSame( 'agent', $message['role'] );
		$this->assertSame( 'Reply', $message['parts'][0]['text'] );
		$this->assertSame( 'ctx-2', $message['contextId'] );
		$this->assertSame( array( 'k' => 'v' ), $message['metadata'] );
	}

	public function test_webhook_handler_event_routing(): void {
		$fired = 0;
		add_action(
			'wp_mcp_ai_a2a_webhook_task_update',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$result = WebhookHandler::handle_inbound( array( 'task' => array( 'id' => 't1' ) ) );
		$this->assertTrue( $result );
		$this->assertSame( 1, $fired );

		$this->assertWPError( WebhookHandler::handle_inbound( 'not-an-array' ) );

		$unknown = WebhookHandler::handle_inbound( array( 'unknown' => 'data' ) );
		$this->assertWPError( $unknown );
		$this->assertSame( 'a2a_unknown_event', $unknown->get_error_code() );
	}

	public function test_client_capability_and_skill_helpers(): void {
		$card = array(
			'capabilities' => array( 'streaming' => true ),
			'skills'       => array(
				array(
					'name'        => 'Translation',
					'description' => 'Translates text',
					'tags'        => array( 'language' ),
				),
			),
		);

		$this->assertTrue( Client::has_capability( $card, 'streaming' ) );
		$this->assertFalse( Client::has_capability( $card, 'pushNotifications' ) );
		$this->assertNotNull( Client::find_skill( $card, 'translation' ) );
		$this->assertNull( Client::find_skill( $card, 'accounting' ) );
	}

	public function test_agent_card_generic_site_card_and_exposed_assistants(): void {
		update_option( 'wp_mcp_ai_settings', array() );

		$card = AgentCard::build_site_card();
		$this->assertArrayHasKey( 'name', $card );
		$this->assertArrayHasKey( 'protocolVersion', $card );
		$this->assertSame( AgentCard::PROTOCOL_VERSION, $card['protocolVersion'] );
		$this->assertSame( array(), AgentCard::get_exposed_assistants() );
	}

	public function test_agent_card_exposed_assistants_from_settings(): void {
		update_option(
			'wp_mcp_ai_settings',
			array( 'a2a_exposed_assistants' => array( '12', '34' ) )
		);

		$this->assertSame( array( 12, 34 ), AgentCard::get_exposed_assistants() );
	}
}
