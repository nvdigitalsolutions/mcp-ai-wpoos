<?php
/**
 * CoreBridge — wires nvoos/core services with WordPress adapters.
 *
 * Creates and holds the canonical singleton instances of:
 *  - WordPress adapter implementations (ErrorFactory, SettingsStore, …)
 *  - Core application services (ProviderRouter, ToolRegistry, ChatOrchestrator)
 *  - All 12 built-in AI provider clients
 *  - All 13 Content Graph-AI tools
 *
 * The plugin's Plugin.php and REST controllers obtain orchestration
 * services through this bridge instead of managing providers/tools directly.
 *
 * @package NvoosContentGraphAi
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAi;

use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry as CoreToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Infrastructure\Cost\CostCalculator;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use Nvoos\WordPress\Adapter\AuthProvider;
use Nvoos\WordPress\Adapter\ErrorFactory;
use Nvoos\WordPress\Adapter\EventDispatcher;
use Nvoos\WordPress\Adapter\HttpClient as WordPressHttpClient;
use NvoosContentGraphAi\Adapter\ContentGraphSettingsStore;
use NvoosContentGraphAi\Adapter\WordPressHttpClient as Psr18HttpClient;
use NvoosContentGraphAi\Embeddings\EmbeddingService;
use NvoosContentGraphAi\Embeddings\RagRetriever;
use NvoosContentGraphAi\Memory\AgentMemory;
use Psr\Http\Client\ClientInterface;

final class CoreBridge {

	private static ?self $instance = null;

	// ─── Adapters ────────────────────────────────────────────────
	public readonly ErrorFactoryInterface $errors;
	public readonly SettingsStoreInterface $settings;
	public readonly EventDispatcherInterface $events;
	public readonly HttpClientInterface $http;
	public readonly ClientInterface $psrHttp;

	// ─── Core services ───────────────────────────────────────────
	public readonly ProviderRouter $providers;
	public readonly CoreToolRegistry $tools;
	public readonly ChatOrchestrator $chat;

	// ─── AI services ─────────────────────────────────────────────
	public readonly Embeddings\EmbeddingService $embeddings;
	public readonly Embeddings\RagRetriever $rag;
	public readonly Memory\AgentMemory $memory;

	private function __construct() {
		// 1. Create WordPress adapters.
		$this->errors = new ErrorFactory();
		// ContentGraphSettingsStore (v2) reads from nvoos_content_graph_settings;
		// the wordpress-adapter's SettingsStore (v1) reads from wp_mcp_ai_settings.
		$this->settings = new ContentGraphSettingsStore();
		$this->events   = new EventDispatcher();
		$this->http     = new WordPressHttpClient();
		$this->psrHttp  = new Psr18HttpClient();

		// 2. Wire core application services.
		$this->providers = new ProviderRouter( $this->settings, $this->errors );
		$this->tools     = new CoreToolRegistry( $this->events, $this->errors );

		$costs = new CostCalculator();
		$sse   = class_exists( 'WP_MCP_AI_WordPress_Flush' )
			? new SseHandler( new \WP_MCP_AI_WordPress_Flush() )
			: new SseHandler(
				new class() implements \Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface {
					public function flushPlatformBuffers(): void {
						if ( \function_exists( 'wp_ob_end_flush_all' ) ) {
							\wp_ob_end_flush_all();
						}
					}
				}
			);

		$this->chat = new ChatOrchestrator(
			$this->tools,
			$this->providers,
			$this->events,
			$this->errors,
			$costs,
			$sse,
		);

		// Wire per-tool capability checks. ToolRegistry::execute() fails
		// closed when no auth provider is set, so every tool declaring a
		// required capability (all graph tools and AI tools) would deny
		// even administrators in the chat tester.
		$this->chat->setAuthProvider( new AuthProvider() );

		// 3. Register built-in providers.
		$this->registerBuiltinProviders();

		// 4. Register AI tools.
		$this->registerAiTools();

		// 5. Wire embeddings + RAG + memory.
		$this->embeddings = new Embeddings\EmbeddingService(
			$this->settings,
			$this->psrHttp,
			$this->errors,
		);
		$this->rag        = new Embeddings\RagRetriever(
			$this->embeddings,
			$this->errors,
		);
		$this->memory     = new Memory\AgentMemory(
			$this->rag,
			$this->embeddings,
		);
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ─── Provider registration ────────────────────────────────────

	private function registerBuiltinProviders(): void {
		// Map of provider slug => FQCN of the core infrastructure client.
		$providerClasses = array(
			'openai'       => \Nvoos\Core\Infrastructure\Provider\OpenAiClient::class,
			'gemini'       => \Nvoos\Core\Infrastructure\Provider\GeminiClient::class,
			'anthropic'    => \Nvoos\Core\Infrastructure\Provider\AnthropicClient::class,
			'ollama'       => \Nvoos\Core\Infrastructure\Provider\OllamaClient::class,
			'deepseek'     => \Nvoos\Core\Infrastructure\Provider\DeepSeekClient::class,
			'openrouter'   => \Nvoos\Core\Infrastructure\Provider\OpenRouterClient::class,
			'huggingface'  => \Nvoos\Core\Infrastructure\Provider\HuggingFaceClient::class,
			'cloudflare'   => \Nvoos\Core\Infrastructure\Provider\CloudflareClient::class,
			'lm_studio'    => \Nvoos\Core\Infrastructure\Provider\LmStudioClient::class,
			'nvidia_nim'   => \Nvoos\Core\Infrastructure\Provider\NvidiaNimClient::class,
			'digitalocean' => \Nvoos\Core\Infrastructure\Provider\DigitalOceanClient::class,
			'kimi'         => \Nvoos\Core\Infrastructure\Provider\KimiClient::class,
			'baseten'      => \Nvoos\Core\Infrastructure\Provider\BasetenClient::class,
		);

		foreach ( $providerClasses as $slug => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$client = new $class( $this->settings, $this->http, $this->errors );
			$this->providers->register( $client );
		}
	}

	// ─── Tool registration ────────────────────────────────────────

	private function registerAiTools(): void {
		$toolNames = array(
			'SummarizeText',
			'TranslateText',
			'AnalyzeSentiment',
			'ExtractEntities',
			'QuestionAnswering',
			'GenerateExcerpt',
			'GenerateImageAltText',
			'AnalyzeImage',
			'CategorizeContent',
			'ContentRecommendation',
			'ContentFreshness',
			'SemanticSearch',
			'CreateTextEmbeddings',
		);

		foreach ( $toolNames as $name ) {
			$class = __NAMESPACE__ . '\\Tools\\' . $name;
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$tool = new $class( $this->errors );
			$this->tools->register( $tool );

			// Also register with the parent plugin's ToolRegistry for
			// backward compatibility (existing hooks, admin UI, etc.).
			if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
				$parentRegistry = \nvoos_content_graph_get_tool_registry();
				if ( $parentRegistry instanceof \NvoosContentGraph\ToolRegistry ) {
					$parentRegistry->register( $tool );
				}
			}
		}

		$this->tools->notifyRegistered();
	}

	// ─── Graph tool bridging ────────────────────────────────────────

	/**
	 * Bridge the parent plugin's graph tools into the core tool registry.
	 *
	 * The parent plugin registers its built-in tools during the
	 * `nvoos_content_graph/register_tools` action (fired at
	 * plugins_loaded priority 10 — after this addon boots at priority 5).
	 * Hooking at priority 20 guarantees every graph tool is present when
	 * we wrap it, making them resolvable and executable by the agentic
	 * chat loop.
	 *
	 * @return void
	 */
	public function registerGraphToolBridge(): void {
		\add_action(
			'nvoos_content_graph/register_tools',
			function ( \NvoosContentGraph\ToolRegistry $parentRegistry ): void {
				foreach ( $parentRegistry->all() as $slug => $tool ) {
					// AI tools are registered directly into the core registry
					// by registerAiTools(); never wrap them twice.
					if ( $this->tools->has( $slug ) ) {
						continue;
					}

					// The has() guard above makes a duplicate registration
					// impossible; should one still slip through, the
					// exception is non-fatal for the chat loop.
					try {
						$this->tools->register( new Adapter\GraphToolAdapter( $tool ) );
					} catch ( \RuntimeException $e ) {
						\do_action( 'nvoos_content_graph_ai_graph_tool_bridge_skipped', $slug, $e->getMessage() );
					}
				}

				$this->tools->notifyRegistered();
			},
			20
		);
	}

	// ─── Provider listing helper (backward compat) ─────────────────

	/**
	 * Get registered provider slugs.
	 *
	 * @return string[]
	 */
	public function getProviderSlugs(): array {
		return $this->providers->getRegisteredSlugs();
	}

	private function __clone() {}
}
