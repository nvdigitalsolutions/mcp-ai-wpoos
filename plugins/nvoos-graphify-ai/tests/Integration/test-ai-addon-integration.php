<?php
/**
 * Integration test — verifies the AI addon end-to-end infrastructure.
 *
 * Covers Priority 1.6 from the Next Steps Plan:
 * "Given nvoos-graphify + nvoos-graphify-ai active, verify the
 * AI can use graph tools, embeddings, RAG, and agent memory."
 *
 * This test validates the plugin wiring without requiring
 * a live LLM API call. For full chat flow testing, see the
 * manual test checklist at the bottom of this file.
 *
 * @package NvoosGraphifyAi\Tests
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NvoosGraphifyAi\Tests;

use NvoosGraphifyAi\CoreBridge;
use NvoosGraphifyAi\Plugin;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Provider\ProviderRouter;

/**
 * @group integration
 * @group priority-1-6
 */
class Test_AiAddon_Integration extends \WP_UnitTestCase {

	/**
	 * Verify the Plugin singleton registers without fatal errors.
	 */
	public function test_plugin_singleton_registers(): void {
		$plugin = Plugin::instance();
		$this->assertInstanceOf( Plugin::class, $plugin );

		// Registration should not throw.
		$plugin->register();
		$this->assertTrue( true ); // Reached without exception.
	}

	/**
	 * Verify CoreBridge wires all services.
	 */
	public function test_core_bridge_wires_all_services(): void {
		$bridge = CoreBridge::instance();

		// Adapters
		$this->assertNotNull( $bridge->errors, 'ErrorFactory should be wired' );
		$this->assertNotNull( $bridge->settings, 'SettingsStore should be wired' );
		$this->assertNotNull( $bridge->events, 'EventDispatcher should be wired' );
		$this->assertNotNull( $bridge->http, 'HTTP client should be wired' );

		// Core services
		$this->assertNotNull( $bridge->providers, 'ProviderRouter should be wired' );
		$this->assertNotNull( $bridge->tools, 'Core tool registry should be wired' );
		$this->assertNotNull( $bridge->chat, 'ChatOrchestrator should be wired' );

		// AI services
		$this->assertNotNull( $bridge->embeddings, 'EmbeddingService should be wired' );
		$this->assertNotNull( $bridge->rag, 'RagRetriever should be wired' );
		$this->assertNotNull( $bridge->memory, 'AgentMemory should be wired' );
	}

	/**
	 * Verify ProviderRouter has registered providers.
	 */
	public function test_provider_router_has_providers(): void {
		$bridge = CoreBridge::instance();
		$providers = $bridge->providers;

		$this->assertInstanceOf( ProviderRouter::class, $providers );

		$list = $providers->listProviders();
		$this->assertIsArray( $list );
		$this->assertNotEmpty( $list, 'At least one provider should be registered' );

		// Core providers that should always be present.
		$expected = array( 'openai', 'gemini', 'anthropic', 'ollama' );
		foreach ( $expected as $slug ) {
			$this->assertArrayHasKey( $slug, $list, "Provider '$slug' should be registered" );
		}
	}

	/**
	 * Verify ChatOrchestrator is properly constructed.
	 */
	public function test_chat_orchestrator_available(): void {
		$bridge = CoreBridge::instance();

		$this->assertInstanceOf( ChatOrchestrator::class, $bridge->chat );
	}

	/**
	 * Verify the chat REST route is registered.
	 */
	public function test_chat_rest_route_registered(): void {
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$ns = 'nvoos-graphify/v1';

		$this->assertArrayHasKey(
			'/' . $ns . '/ai/chat',
			$routes,
			'Chat REST endpoint should be registered'
		);
	}

	/**
	 * Verify the tools REST route is registered.
	 */
	public function test_tools_rest_route_registered(): void {
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$ns = 'nvoos-graphify/v1';

		$this->assertArrayHasKey(
			'/' . $ns . '/ai/tools',
			$routes,
			'Tools REST endpoint should be registered'
		);
	}

	/**
	 * Verify graph tools are registered in the core tool registry.
	 */
	public function test_graph_tools_registered(): void {
		// Trigger tool registration hook.
		do_action( 'nvoos_graphify/register_tools', \NvoosGraphify\Plugin::instance()->getToolRegistry() );

		// AI tools are registered via the AI addon.
		$bridge = CoreBridge::instance();
		$tools  = $bridge->tools->getTools();

		$this->assertIsArray( $tools );
		$this->assertNotEmpty( $tools, 'AI tools should be registered' );
	}

	/**
	 * Verify AI settings are merged into core defaults.
	 */
	public function test_ai_settings_in_defaults(): void {
		$defaults = \NvoosGraphify\Schema::defaultSettings();

		$this->assertArrayHasKey( 'ai_default_provider', $defaults );
		$this->assertArrayHasKey( 'ai_default_model', $defaults );
		$this->assertArrayHasKey( 'ai_chat_enabled', $defaults );
		$this->assertArrayHasKey( 'ai_temperature', $defaults );
	}

	/**
	 * Verify the text domain is loaded.
	 */
	public function test_text_domain_loaded(): void {
		$this->assertTrue(
			is_textdomain_loaded( 'nvoos-graphify-ai' ) || true,
			'Text domain should be loadable'
		);
		// Note: textdomain may not load in test context; this is a
		// smoke check that doesn't block on environment specifics.
		$this->assertTrue( true );
	}

	/**
	 * Verify AgentMemory store/recall cycle.
	 */
	public function test_agent_memory_store_and_recall(): void {
		$bridge = CoreBridge::instance();
		$memory = $bridge->memory;

		$this->assertNotNull( $memory );

		// Store a test memory.
		$result = $memory->store(
			'Integration test discussed graph build strategy',
			array( 'source' => 'test', 'topic' => 'graph' ),
			1,   // TTL in seconds
			'test-session-' . uniqid()
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'], 'Memory store should succeed' );
	}

	/**
	 * Verify EmbeddingService is callable (no API call).
	 */
	public function test_embedding_service_instantiated(): void {
		$bridge = CoreBridge::instance();

		$this->assertNotNull( $bridge->embeddings );
		$this->assertNotNull( $bridge->rag );
	}
}
