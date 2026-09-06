<?php
/**
 * Chat Controller tests — REST contract for the Chat Tester.
 *
 * Verifies:
 *  - /ai/chat declares and forwards model/temperature/max_tokens/tools/system_prompt
 *  - /ai/chat/config and /ai/tools are registered and shaped correctly
 *  - system prompt injection behaves (prepend / skip / disabled)
 *
 * @package NvoosContentGraphAi\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Plugin;
use NvoosContentGraphAi\CoreBridge;
use NvoosContentGraphAi\Rest\ChatController;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;

/**
 * @group integration
 * @group chat-tester
 */
class Test_Chat_Controller extends \WP_UnitTestCase {

	/**
	 * Boot the addon once so routes, settings defaults, and CoreBridge exist.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( class_exists( 'NvoosContentGraphAi\\Plugin' ) ) {
			Plugin::instance()->register();
		}
	}

	/**
	 * The /ai/chat route must accept the tester parameters.
	 */
	public function test_chat_route_declares_tester_args(): void {
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$route  = $routes['/nvoos-content-graph/v1/ai/chat'] ?? array();

		$this->assertNotEmpty( $route, 'Chat route should be registered' );

		$args = $route[0]['args'] ?? array();
		foreach ( array( 'messages', 'provider', 'model', 'temperature', 'max_tokens', 'tools', 'stream', 'system_prompt', 'include_context' ) as $key ) {
			$this->assertArrayHasKey( $key, $args, "Chat route should declare '{$key}'" );
		}
	}

	/**
	 * The config route must be registered.
	 */
	public function test_config_route_registered(): void {
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey(
			'/nvoos-content-graph/v1/ai/chat/config',
			$routes,
			'Config endpoint should be registered'
		);
	}

	/**
	 * The tools route must be registered (also required by the legacy
	 * integration suite).
	 */
	public function test_tools_route_registered(): void {
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey(
			'/nvoos-content-graph/v1/ai/tools',
			$routes,
			'Tools endpoint should be registered'
		);
	}

	/**
	 * The models route must be registered.
	 */
	public function test_models_route_registered(): void {
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey(
			'/nvoos-content-graph/v1/ai/models',
			$routes,
			'Models endpoint should be registered'
		);
	}

	/**
	 * The config payload must expose the shape the JS tester consumes.
	 */
	public function test_get_chat_config_shape(): void {
		$response = ( new ChatController() )->getChatConfig();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertIsArray( $data['providers'] );
		$this->assertNotEmpty( $data['providers'] );

		$first = $data['providers'][0];
		$this->assertArrayHasKey( 'slug', $first );
		$this->assertArrayHasKey( 'label', $first );
		$this->assertArrayHasKey( 'configured', $first );

		$this->assertIsString( $data['default_provider'] );
		$this->assertIsString( $data['default_model'] );
		$this->assertIsFloat( $data['temperature'] );
		$this->assertIsInt( $data['max_tokens'] );
		$this->assertIsBool( $data['graph_context_available'] );
		$this->assertContains( $data['graph_context_mode'], array( 'none', 'keyword', 'rag' ) );
		$this->assertIsArray( $data['tool_presets'] );
		$this->assertIsArray( $data['tools'] );

		$presets = wp_list_pluck( $data['tool_presets'], 'slug' );
		$this->assertContains( 'none', $presets );
		$this->assertContains( 'graph', $presets );
	}

	/**
	 * System prompt injection — prepend when enabled.
	 */
	public function test_build_messages_prepends_system_prompt(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'ai_system_prompt' => 'You are a graph assistant.' )
		);

		$controller = new ChatController();
		$messages   = $controller->buildMessages(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			),
			true
		);

		$this->assertSame( 'system', $messages[0]['role'] );
		$this->assertSame( 'You are a graph assistant.', $messages[0]['content'] );
		$this->assertCount( 2, $messages );
	}

	/**
	 * System prompt injection — skipped when disabled.
	 */
	public function test_build_messages_skips_when_disabled(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'ai_system_prompt' => 'You are a graph assistant.' )
		);

		$controller = new ChatController();
		$messages   = $controller->buildMessages(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			),
			false
		);

		$this->assertCount( 1, $messages );
		$this->assertSame( 'user', $messages[0]['role'] );
	}

	/**
	 * System prompt injection — never duplicated.
	 */
	public function test_build_messages_does_not_duplicate_system(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'ai_system_prompt' => 'You are a graph assistant.' )
		);

		$controller = new ChatController();
		$messages   = $controller->buildMessages(
			array(
				array(
					'role'    => 'system',
					'content' => 'Custom system.',
				),
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			),
			true
		);

		$this->assertCount( 2, $messages );
		$this->assertSame( 'Custom system.', $messages[0]['content'] );
	}

	/**
	 * Tool slugs are sanitized to safe keys (spaces and punctuation are
	 * stripped by sanitize_key; the server-side filterAllowedTools() then
	 * drops anything not registered).
	 */
	public function test_sanitize_tools(): void {
		$controller = new ChatController();

		$sanitized = $controller->sanitizeTools(
			array( 'nvoos_content_graph_query_graph', 'not a slug', 42, 'ai_summarize_text' )
		);

		$this->assertSame(
			array( 'nvoos_content_graph_query_graph', 'notaslug', 'ai_summarize_text' ),
			$sanitized
		);
	}

	/**
	 * Unknown providers yield a 404-style error without any upstream call.
	 */
	public function test_get_models_unknown_provider_errors(): void {
		$request = new \WP_REST_Request( 'GET', '/nvoos-content-graph/v1/ai/models' );
		$request->set_param( 'provider', 'not-a-provider' );

		$result = ( new ChatController() )->getModels( $request );

		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * The models list is transient-cached; the filter can supply it
	 * without an upstream HTTP call.
	 */
	public function test_get_models_uses_filter_and_caches(): void {
		$provider = 'openai';
		$key      = 'nvoos_content_graph_ai_models_' . $provider;
		delete_transient( $key );

		add_filter(
			'nvoos_content_graph_ai_models_list',
			static function ( $models, $slug ) use ( $provider ) {
				return $slug === $provider ? array( 'gpt-4o', 'gpt-4o-mini' ) : $models;
			},
			10,
			2
		);

		$request = new \WP_REST_Request( 'GET', '/nvoos-content-graph/v1/ai/models' );
		$request->set_param( 'provider', $provider );

		$first = ( new ChatController() )->getModels( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $first );
		$data = $first->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( array( 'gpt-4o', 'gpt-4o-mini' ), $data['models'] );
		$this->assertFalse( $data['cached'] );

		// Second call must hit the transient (filter still returning a
		// value would otherwise mask the cache — assert cached=true).
		remove_all_filters( 'nvoos_content_graph_ai_models_list' );
		$second = ( new ChatController() )->getModels( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $second );
		$data = $second->get_data();
		$this->assertTrue( $data['cached'] );
		$this->assertSame( array( 'gpt-4o', 'gpt-4o-mini' ), $data['models'] );

		delete_transient( $key );
	}

	/**
	 * Graph context is merged into the system prompt when requested.
	 */
	public function test_build_messages_merges_graph_context(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'ai_system_prompt' => 'You are a graph assistant.' )
		);

		add_filter(
			'nvoos_content_graph_ai_chat_context',
			static function () {
				return 'GRAPH CONTEXT: Node A is related to Node B.';
			}
		);

		$controller = new ChatController();
		$messages   = $controller->buildMessages(
			array(
				array(
					'role'    => 'user',
					'content' => 'Tell me about Node A.',
				),
			),
			true,
			true
		);

		$this->assertSame( 'system', $messages[0]['role'] );
		$this->assertStringContainsString( 'You are a graph assistant.', $messages[0]['content'] );
		$this->assertStringContainsString( 'GRAPH CONTEXT', $messages[0]['content'] );
	}

	/**
	 * Context is appended to an existing system message instead of
	 * duplicating it.
	 */
	public function test_build_messages_appends_context_to_existing_system(): void {
		add_filter(
			'nvoos_content_graph_ai_chat_context',
			static function () {
				return 'GRAPH CONTEXT';
			}
		);

		$controller = new ChatController();
		$messages   = $controller->buildMessages(
			array(
				array(
					'role'    => 'system',
					'content' => 'Custom system.',
				),
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			),
			true,
			true
		);

		$this->assertCount( 2, $messages );
		$this->assertStringContainsString( 'Custom system.', $messages[0]['content'] );
		$this->assertStringContainsString( 'GRAPH CONTEXT', $messages[0]['content'] );
	}

	/**
	 * Context is skipped entirely when not requested.
	 */
	public function test_build_messages_skips_context_when_disabled(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'ai_system_prompt' => 'You are a graph assistant.' )
		);

		add_filter(
			'nvoos_content_graph_ai_chat_context',
			static function () {
				return 'GRAPH CONTEXT';
			}
		);

		$controller = new ChatController();
		$messages   = $controller->buildMessages(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			),
			true,
			false
		);

		$this->assertStringNotContainsString( 'GRAPH CONTEXT', $messages[0]['content'] );
	}

	/**
	 * Without embeddings, graph context falls back to keyword search over
	 * node labels so the tester still connects to the graph.
	 */
	public function test_build_messages_uses_keyword_graph_context_fallback(): void {
		\NvoosContentGraph\Graph\Db::upsertNode(
			array(
				'node_id' => 'test_node_chat_context',
				'label'   => 'SEO Strategy Guide',
				'type'    => 'post',
				'post_id' => 0,
				'url'     => '',
			)
		);

		try {
			$controller = new ChatController();
			$messages   = $controller->buildMessages(
				array(
					array(
						'role'    => 'user',
						'content' => 'Tell me about the SEO strategy',
					),
				),
				true,
				true
			);

			$this->assertSame( 'system', $messages[0]['role'] );
			$this->assertStringContainsString( 'SEO Strategy Guide', $messages[0]['content'] );
			$this->assertStringContainsString( 'knowledge graph may be relevant', $messages[0]['content'] );
		} finally {
			\NvoosContentGraph\Graph\Db::deleteNode( 'test_node_chat_context' );
		}
	}

	/**
	 * The AI settings defaults include the system prompt.
	 */
	public function test_system_prompt_in_defaults(): void {
		$defaults = \NvoosContentGraph\Schema::defaultSettings();

		$this->assertArrayHasKey( 'ai_system_prompt', $defaults );
		$this->assertNotSame( '', $defaults['ai_system_prompt'] );
	}

	/**
	 * Capability regression — the chat tester must not deny capable users.
	 *
	 * CoreBridge must wire a WordPress AuthProvider into the
	 * ChatOrchestrator. Without it, ToolRegistry::execute() fails closed
	 * and every tool declaring a required capability (all graph tools)
	 * is denied even for administrators, surfacing as
	 * "You do not have permission to execute '...'" in the chat tester.
	 */
	public function test_graph_stats_tool_executes_for_capable_user(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		// Ensure the graph tools are bridged into the core registry
		// (idempotent — the bridge skips slugs already registered).
		do_action(
			\NvoosContentGraph\Schema::ACTION_REGISTER_TOOLS,
			\NvoosContentGraph\Plugin::instance()->getToolRegistry()
		);

		$bridge = CoreBridge::instance();

		// The orchestrator must hold a wired auth provider (the root cause).
		$reflection = new \ReflectionProperty( ChatOrchestrator::class, 'authProvider' );
		$reflection->setAccessible( true );
		$authProvider = $reflection->getValue( $bridge->chat );
		$this->assertInstanceOf( AuthProviderInterface::class, $authProvider );

		// Execute through the core registry exactly as the agentic loop does.
		$result = $bridge->tools->execute(
			'nvoos_content_graph_graph_stats',
			array(),
			array(
				'user_id'       => get_current_user_id(),
				'assistant_id'  => 0,
				'agentic_loop'  => true,
				'iteration'     => 1,
				'auth_provider' => $authProvider,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( (bool) ( $result['success'] ?? false ) );
		$this->assertArrayHasKey( 'stats', $result );
	}
}
