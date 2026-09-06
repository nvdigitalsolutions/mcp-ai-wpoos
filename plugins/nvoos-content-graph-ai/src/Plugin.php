<?php
declare(strict_types=1);

namespace NvoosContentGraphAi;

/**
 * Plugin bootstrap — wires the addon into WordPress and the parent plugin.
 *
 * Replaces direct ProviderRegistry / ChatService management with
 * CoreBridge, which delegates provider routing, tool execution, and
 * chat orchestration to the framework-agnostic nvoos/core engine.
 *
 * @since 1.0.0
 */
final class Plugin {

	private static ?self $instance = null;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		// Bootstrap core services (providers, tools, orchestrator,
		// embeddings, RAG, agent memory).
		$bridge = CoreBridge::instance();

		// Expose parent-plugin graph tools to the agentic chat loop.
		$bridge->registerGraphToolBridge();

		// Admin UI.
		if ( is_admin() ) {
			$this->registerAdmin();
		}

		add_filter( 'nvoos_content_graph/default_settings', array( $this, 'addDefaultSettings' ) );

		// Credential hardening — encrypted API key storage, masked
		// rendering, settings-save strip filter, and one-time migration.
		if ( class_exists( 'NvoosContentGraphAi\Security\CredentialStore' ) ) {
			\NvoosContentGraphAi\Security\CredentialStore::register();
		}

		// WP-CLI surface (wp nvoos-cg-ai migrate-keys / key-status).
		add_action( 'cli_init', array( \NvoosContentGraphAi\Cli::class, 'registerCommands' ) );

		add_action(
			'rest_api_init',
			static function (): void {
				if ( class_exists( 'NvoosContentGraphAi\Rest\ChatController' ) ) {
					( new \NvoosContentGraphAi\Rest\ChatController() )->registerRoutes();
				}

				// Assistant directory + tools listing (mcp-ai/v1) — the base
				// plugin owns the same routes in monolith installs; double
				// registration would conflict.
				if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
					if ( class_exists( 'NvoosContentGraphAi\Rest\AssistantController' ) ) {
						( new \NvoosContentGraphAi\Rest\AssistantController() )->registerRoutes();
					}
					if ( class_exists( 'NvoosContentGraphAi\Rest\ToolsController' ) ) {
						( new \NvoosContentGraphAi\Rest\ToolsController() )->registerRoutes();
					}
					if ( class_exists( 'NvoosContentGraphAi\Rest\McpController' ) ) {
						( new \NvoosContentGraphAi\Rest\McpController() )->registerRoutes();
					}
					if ( class_exists( 'NvoosContentGraphAi\Rest\ChatCompatController' ) ) {
						( new \NvoosContentGraphAi\Rest\ChatCompatController() )->registerRoutes();
					}
				}
			}
		);

		// Register embeddings-on-ingest and agent memory hooks.
		( new \NvoosContentGraphAi\Embeddings\EmbeddingsOnIngest(
			$bridge->embeddings,
			$bridge->settings,
			$bridge->errors,
		) )->register();

		$bridge->memory->register();

		// Attachment lifecycle cleanup + assistant response-file persistence.
		// Standalone only — the base plugin owns these hooks in monolith
		// installs; registering here too would double-process file deletions.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			\NvoosContentGraphAi\Chat\MessageAttachments::init();
			\NvoosContentGraphAi\Chat\ResponseAttachments::init();

			// Transcript retention sweep (cron + GDPR deletion endpoint).
			// The base plugin owns the same cron hook and REST route in
			// monolith installs — double registration would double-sweep.
			\NvoosContentGraphAi\Chat\TranscriptRetention::init();

			// ChatKit integration. The base plugin owns the same ChatKit
			// hooks in monolith installs — registering here too would
			// publish two competing addons.
			\NvoosContentGraphAi\Chat\ChatKitIntegration::init();

			// Model catalog migration — the base plugin owns the same init
			// routine in monolith installs; double-running would double-write
			// the bookkeeping option.
			\add_action( 'init', array( \NvoosContentGraphAi\Model\ModelCatalogMigration::class, 'run_from_catalog' ), 20 );

			// Model rate limits CCT + pricing checker — the base plugin owns
			// the same JetEngine provisioning and cron hooks in monolith
			// installs; registering here too would double-provision.
			\NvoosContentGraphAi\Model\ModelRateLimitsCct::bootstrap();
			\NvoosContentGraphAi\Model\ModelPricingChecker::bootstrap();

			// Usage tracker user-deletion hooks — the base plugin owns the
			// same hooks in monolith installs.
			\NvoosContentGraphAi\Analytics\UsageTracker::init();

			// Token tracking database + enhanced tracking + optimizer — the
			// base plugin owns the same hooks/schema in monolith installs;
			// double registration would double-record.
			\NvoosContentGraphAi\Analytics\TokenTrackingDatabase::init();
			\NvoosContentGraphAi\Analytics\EnhancedTokenTracking::init();
			\NvoosContentGraphAi\Analytics\TokenDbOptimizer::init();

			// Tool token limits — the base plugin owns the same usage/tier
			// hooks in monolith installs; double registration would
			// double-record usage into the same meta key.
			\NvoosContentGraphAi\Analytics\ToolTokenLimits::init();

			// Security audit logger — the base plugin owns the same REST
			// route (`mcp-ai/v1/security/events`) and purge cron in monolith
			// installs; double registration would conflict.
			\NvoosContentGraphAi\Security\SecurityAuditLogger::register();

			// Request guard — the base plugin owns the same REST validation
			// hooks and SSE slot tracking in monolith installs; double
			// registration would double-count SSE connections.
			\NvoosContentGraphAi\Security\RequestGuard::register();

			// Concurrency + cost tracker subscribers — the base plugin owns
			// the same tool-execution hooks (and the shared slot table) in
			// monolith installs; double registration would double-count.
			\NvoosContentGraphAi\Security\ConcurrencyGuardSubscriber::register();
			\NvoosContentGraphAi\Security\CostTrackerSubscriber::register();

			// Destructive ops gate, CSP headers, and load guard — the base
			// plugin owns the same hooks in monolith installs.
			\NvoosContentGraphAi\Security\DestructiveOpsGate::register();
			\NvoosContentGraphAi\Security\CspHeaders::register();
			\NvoosContentGraphAi\Security\LoadGuard::register();

			// Agent memory CCT trio (Wave D7) — the base plugin registers
			// the same CCT, lifecycle listeners, migrator, and recall filter
			// in monolith installs; double registration would double-mirror
			// writes and double-merge recall candidates. Dormant standalone
			// until the memory/tools wave emits wp_mcp_ai_memory_stored
			// (tracked gap).
			\NvoosContentGraphAi\Memory\AgentMemoriesCct::bootstrap();
			\NvoosContentGraphAi\Memory\AgentMemoryCctBridge::bootstrap();
			\NvoosContentGraphAi\Memory\AgentMemoryCctMigrator::bootstrap();
			\NvoosContentGraphAi\Memory\AgentMemoryCctReader::bootstrap();

			// Assistant CPT (Wave D-UI-4) — the base plugin owns the same
			// CPT + meta in monolith installs; double registration would
			// conflict.
			\NvoosContentGraphAi\Admin\AssistantPostType::register();
		}

		// Register the async chat continuation hook.
		add_action(
			'nvoos_content_graph_ai/continue_chat',
			array( $this, 'handleContinueChat' ),
			10,
			2
		);

		// Provider/model settings changed — drop cached model lists so
		// the Chat Tester re-fetches them on the next request.
		add_action(
			'update_option_' . \NvoosContentGraph\Schema::OPTION_SETTINGS,
			static function (): void {
				if ( class_exists( 'NvoosContentGraphAi\Rest\ChatController' ) ) {
					\NvoosContentGraphAi\Rest\ChatController::clearModelCache();
				}
			}
		);

		// Frontend chat widget (Wave D-UI-1b) — registered in both install
		// modes; the tag ([nvoos_content_graph_chat]) is ecosystem-specific
		// and never collides with the base's [mcp_ai_chat].
		if ( class_exists( 'NvoosContentGraphAi\Frontend\ChatShortcode' ) ) {
			( new \NvoosContentGraphAi\Frontend\ChatShortcode() )->register();
		}

		// Chat-family blocks (Wave D-UI-2) — ecosystem block names never
		// collide with the base's mcp-ai-wpoos/* blocks.
		if ( class_exists( 'NvoosContentGraphAi\Blocks\Blocks' ) ) {
			( new \NvoosContentGraphAi\Blocks\Blocks() )->register();
		}

		// Elementor chat-family widgets (Wave D-UI-3) — the hub no-ops
		// when Elementor is inactive; widget names never collide with
		// the base's wp_mcp_ai_* Elementor widgets.
		if ( class_exists( 'NvoosContentGraphAi\Elementor\ElementorHub' ) ) {
			( new \NvoosContentGraphAi\Elementor\ElementorHub() )->register();
		}

		// Engine pieces (Wave E6, sub-cluster 1 — OOS shadow runner +
		// suppression). Standalone-only (see registerEngine()).
		$this->registerEngine();
	}

	/**
	 * Register the engine pieces (Wave E6).
	 *
	 * Standalone-only: the base plugin's bridge/init files own the same
	 * shadow-runner subscriber, suppression waterfall, markup wiring,
	 * Paper Store tool registration, and OKF tool registration +
	 * generator hooks in monolith installs; double registration would
	 * double-record shadow runs, double-short-circuit write-class tools,
	 * and double-register tools. Dormant until a standalone surface emits
	 * the respective hooks (byte-identical dormancy).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function registerEngine(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( 'NvoosContentGraphAi\Engine\OosShadowRunner' ) ) {
			\NvoosContentGraphAi\Engine\OosShadowRunner::register();
		}

		if ( class_exists( 'NvoosContentGraphAi\Engine\OosShadowSuppression' ) ) {
			\NvoosContentGraphAi\Engine\OosShadowSuppression::register();
		}

		// Markup elicitation subsystem (Wave E6, sub-cluster 2) — the base
		// `markup-init.php` owns the same hooks monolith.
		if ( class_exists( 'NvoosContentGraphAi\Engine\Markup\MarkupBootstrap' ) ) {
			\NvoosContentGraphAi\Engine\Markup\MarkupBootstrap::register();
		}

		// Paper Store engine (Wave E6, sub-cluster 3) — the base
		// `paper-store-init.php` owns the same tool registration monolith.
		if ( class_exists( 'NvoosContentGraphAi\Engine\PaperStore\PaperStoreBootstrap' ) ) {
			\NvoosContentGraphAi\Engine\PaperStore\PaperStoreBootstrap::register();
		}

		// OKF engine (Wave E6, sub-cluster 4) — the base `okf-init.php`
		// owns the same tool registration + generator hooks monolith.
		if ( class_exists( 'NvoosContentGraphAi\Engine\Okf\OkfBootstrap' ) ) {
			\NvoosContentGraphAi\Engine\Okf\OkfBootstrap::register();
		}
	}

	/**
	 * Register admin components.
	 *
	 * @return void
	 */
	private function registerAdmin(): void {
		if ( class_exists( 'NvoosContentGraphAi\Admin\AiSettingsPage' ) ) {
			$aiSettings = new \NvoosContentGraphAi\Admin\AiSettingsPage();
			$aiSettings->register();
		}

		// Assistant builder/add pages (Wave D-UI-4) — the base plugin owns
		// the same admin pages in monolith installs; the ecosystem hub
		// stands down so the menus are never duplicated.
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'NvoosContentGraphAi\Admin\AssistantPages' ) ) {
			( new \NvoosContentGraphAi\Admin\AssistantPages() )->register();
		}
	}

	public function addDefaultSettings( array $defaults ): array {
		return array_merge(
			$defaults,
			array(
				'ai_default_provider'      => 'openai',
				'ai_default_model'         => 'gpt-4o',
				'ai_chat_enabled'          => true,
				'ai_temperature'           => 0.7,
				'ai_max_tokens'            => 4096,
				'ai_api_key_openai'        => '',
				'ai_api_key_gemini'        => '',
				'ai_api_key_ollama'        => '',
				'ollama_base_url'          => 'http://localhost:11434',
				'ollama_model'             => 'llama3.3',
				'ai_api_key_anthropic'     => '',
				'ai_api_key_deepseek'      => '',
				'ai_api_key_openrouter'    => '',
				'ai_api_key_huggingface'   => '',
				'huggingface_endpoint_url' => 'https://api-inference.huggingface.co',
				'huggingface_model'        => 'meta-llama/Llama-3.3-70B-Instruct',
				'ai_api_key_cloudflare'    => '',
				'cloudflare_account_id'    => '',
				'cloudflare_model'         => '@cf/meta/llama-3.3-70b-instruct',
				'ai_api_key_lmstudio'      => '',
				'lmstudio_base_url'        => 'http://localhost:1234/v1',
				'lmstudio_model'           => 'local-model',
				'ai_api_key_nvidia'        => '',
				'ai_api_key_digitalocean'  => '',
				'ai_api_key_kimi'          => '',
				'ai_api_key_baseten'       => '',
				'ai_api_key_zai'           => '',
				'zai_base_url'             => 'https://api.z.ai/api/paas/v4',
				'ai_system_prompt'         => 'You are a helpful assistant for the NV oOS Content Graph on this WordPress site. Answer questions about the site content and its knowledge graph accurately and concisely. When tools for querying the graph are provided, use them to ground your answers in real data instead of guessing. Cite nodes, posts, or relationships when relevant. If you do not know something or the data is unavailable, say so plainly. Format answers with Markdown.',
				'transcript_retention_days'       => 90,
				'transcript_retention_enabled'    => true,
				'transcript_guest_retention_days' => 7,
				'transcript_per_user_max'         => 500,
			)
		);
	}

	/**
	 * Handle async chat continuation via Action Scheduler.
	 *
	 * @param array  $messages Conversation history.
	 * @param string $provider Provider slug.
	 */
	public function handleContinueChat( array $messages, string $provider = '' ): void {
		$bridge  = CoreBridge::instance();
		$options = array();

		if ( '' !== $provider ) {
			$options['provider'] = $provider;
		}

		$bridge->chat->handleChat(
			$messages,
			array(),           // assistantConfig
			0,                 // userId
			0,                 // assistantId
			$options,
		);
	}

	private function __clone() {}
}
