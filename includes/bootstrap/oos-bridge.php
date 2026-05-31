<?php
/**
 * OOS Bridge — wires the framework-agnostic extraction core into WordPress.
 *
 * This file is loaded during wp_mcp_ai_bootstrapped (priority 12, after the
 * main plugin bootstrap but before addon registration). It:
 *
 *  1. Registers PSR-4 autoloading for lib/core and lib/wordpress-adapter
 *  2. Wires all 8 WordPress adapter implementations to domain interfaces
 *  3. Constructs the ChatOrchestrator with injected adapters
 *  4. Exposes the orchestrator via a global function
 *
 * The existing plugin path is completely unaffected — this is additive only.
 * The new engine is activated via query parameter ?engine=oos or header
 * X-WP-MCP-AI-Engine: oos. Without the flag, existing behavior is unchanged.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only activate on PHP 8.1+ (the core package requires readonly/enums/fibers).
if ( PHP_VERSION_ID < 80100 ) {
	return;
}

// ─── PSR-4 Autoloader ─────────────────────────────────────────────────

// Register PSR-4 autoloading for the extraction packages if not already
// handled by Composer. This is a no-op if composer autoload is present.
if ( ! class_exists( 'Oos\Core\Domain\Contract\ErrorFactoryInterface' ) ) {
	spl_autoload_register( function ( string $class ): void {
		$prefixes = [
			'Oos\\Core\\'      => WP_MCP_AI_PATH . 'lib/core/src/',
			'Oos\\WordPress\\' => WP_MCP_AI_PATH . 'lib/wordpress-adapter/src/',
		];

		foreach ( $prefixes as $prefix => $baseDir ) {
			$len = strlen( $prefix );
			if ( strncmp( $prefix, $class, $len ) !== 0 ) {
				continue;
			}

			$relativeClass = substr( $class, $len );
			$file          = $baseDir . str_replace( '\\', '/', $relativeClass ) . '.php';

			if ( file_exists( $file ) ) {
				require $file;
			}
		}
	} );
}

// ─── Adapter Wiring ───────────────────────────────────────────────────

/**
 * Build the framework-agnostic ChatOrchestrator using WordPress adapters.
 *
 * This factory wires all 8 domain interfaces to their WordPress
 * implementations, constructs the 12 provider clients, registers them
 * with the ProviderRouter, and returns a fully functional orchestrator.
 *
 * @return Oos\Core\Application\Chat\ChatOrchestrator
 */
function wp_mcp_ai_oos_orchestrator() {
	static $orchestrator = null;

	if ( null !== $orchestrator ) {
		return $orchestrator;
	}

	// ─── Adapters ──────────────────────────────────────────────────

	$errorFactory = new Oos\WordPress\Adapter\ErrorFactory();
	$settings     = new Oos\WordPress\Adapter\SettingsStore();
	$content      = new Oos\WordPress\Adapter\ContentStore();
	$auth         = new Oos\WordPress\Adapter\AuthProvider();
	$files        = new Oos\WordPress\Adapter\FileStore();
	$cache        = new Oos\WordPress\Adapter\CacheStore( wp_using_ext_object_cache() );
	$queue        = new Oos\WordPress\Adapter\QueueClient();
	$events       = new Oos\WordPress\Adapter\EventDispatcher();

	// Map existing wp_mcp_ai_* hooks to the event dispatcher for backward compat.
	$events->mapEventToHook(
		'Oos\\Core\\Domain\\Event\\BeforeToolExecution',
		'wp_mcp_ai_before_tool_execution'
	);
	$events->mapEventToHook(
		'Oos\\Core\\Domain\\Event\\AfterToolExecution',
		'wp_mcp_ai_after_tool_execution'
	);
	$events->mapEventToHook(
		'Oos\\Core\\Domain\\Event\\BeforeChatRequest',
		'wp_mcp_ai_before_chat_request'
	);
	$events->mapEventToHook(
		'Oos\\Core\\Domain\\Event\\AfterChatResponse',
		'wp_mcp_ai_after_chat_response'
	);

	// ─── HTTP Client (PSR-18) ──────────────────────────────────────

	// Use Symfony HttpClient with PSR-18 adapter since it's already a dependency.
	$httpClient = new \Symfony\Component\HttpClient\Psr18Client();

	// ─── Provider Clients ──────────────────────────────────────────

	$router = new Oos\Core\Application\Provider\ProviderRouter( $settings, $errorFactory );

	// Register all 12 providers.
	$router->register( new Oos\Core\Infrastructure\Provider\OpenAiClient( $settings, $httpClient, $errorFactory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\GeminiClient( $settings, $httpClient, $errorFactory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\AnthropicClient( $settings, $httpClient, $errorFactory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\DeepSeekClient( $settings, $httpClient, $errorFactory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\OpenRouterClient( $settings, $httpClient, $errorFactory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\KimiClient( $settings, $httpClient, $errorFactory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\OllamaClient( $settings, $httpClient, $errorFactory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\LmStudioClient( $settings, $httpClient, $errorFactory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\DigitalOceanClient( $settings, $httpClient, $errorFactory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\NvidiaNimClient( $settings, $httpClient, $errorFactory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\CloudflareClient( $settings, $httpClient, $errorFactory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\HuggingFaceClient( $settings, $httpClient, $errorFactory ) );

	// ─── Core Services ─────────────────────────────────────────────

	$toolRegistry = new Oos\Core\Application\Tool\ToolRegistry( $events, $errorFactory );

	// ─── Register migrated framework-agnostic tools ────────────────
	// Tier 1: External API / public data tools.
	$toolRegistry->register( new Oos\Core\Tool\WebSearchTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\GetGdacsEventsTool( $errorFactory, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\GetNhcActiveStormsTool( $errorFactory, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\GetOpenMeteoForecastTool( $errorFactory, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\ReliefwebReportsTool( $errorFactory, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\GetModelInformationTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\ListAvailableModelsTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\ModerateContentTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\CreateTextEmbeddingsTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\SuggestBestModelTool( $errorFactory ) );
	$toolRegistry->register( new Oos\Core\Tool\DeepResearchTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\ProbeRemoteMcpTool( $errorFactory, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\RunCrawl4AiJobTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\Crawl4AiPriceLookupTool( $errorFactory, $settings, $httpClient ) );

	// HuggingFace dataset tools.
	$toolRegistry->register( new Oos\Core\Tool\HuggingFaceDatasetSearchTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\HuggingFaceDatasetGetInfoTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\HuggingFaceDatasetGetRowsTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\HuggingFaceDatasetGetSizeTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\HuggingFaceDatasetGetStatisticsTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\HuggingFaceDatasetIsValidTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\HuggingFaceDatasetListSplitsTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\HuggingFaceDatasetFilterTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\HuggingFaceDatasetGetParquetTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\HuggingFaceDatasetPreviewRowsTool( $errorFactory, $settings, $httpClient ) );
	$toolRegistry->register( new Oos\Core\Tool\HuggingFaceRecommendedDatasetsTool( $errorFactory, $settings, $httpClient ) );

	// Client-side tools.
	$toolRegistry->register( new Oos\Core\Tool\ClientAnalyzeSentimentTool( $errorFactory ) );
	$toolRegistry->register( new Oos\Core\Tool\ClientSummarizeTextTool( $errorFactory ) );
	$toolRegistry->register( new Oos\Core\Tool\ClientTranslateTextTool( $errorFactory ) );
	$toolRegistry->register( new Oos\Core\Tool\ClientExtractEntitiesTool( $errorFactory ) );
	$toolRegistry->register( new Oos\Core\Tool\ClientQuestionAnsweringTool( $errorFactory ) );
	$toolRegistry->register( new Oos\Core\Tool\ClientSemanticSearchTool( $errorFactory ) );

	// Content tools (use WordPress ContentStore adapter).
	$toolRegistry->register( new Oos\Core\Tool\GetPostTool( $errorFactory, $content ) );
	$toolRegistry->register( new Oos\Core\Tool\CreatePostTool( $errorFactory, $content ) );
	$toolRegistry->register( new Oos\Core\Tool\GetRecentPostsTool( $errorFactory, $content ) );
	$toolRegistry->register( new Oos\Core\Tool\SearchContentTool( $errorFactory, $content ) );

	// File tools (use WordPress FileStore adapter).
	$toolRegistry->register( new Oos\Core\Tool\SearchAttachmentsTool( $errorFactory, $files ) );

	// Geo tools.
	$toolRegistry->register( new Oos\Core\Tool\GeocodeAddressTool( $errorFactory, $settings, $httpClient ) );

	// Site admin tools.
	$toolRegistry->register( new Oos\Core\Tool\GetSiteSummaryTool( $errorFactory, $settings ) );

	$toolRegistry->notifyRegistered();

	$sse          = new Oos\Core\Infrastructure\Streaming\SseHandler();
	$costs        = new Oos\Core\Infrastructure\Cost\CostCalculator();

	$orchestrator = new Oos\Core\Application\Chat\ChatOrchestrator(
		$toolRegistry,
		$router,
		$events,
		$errorFactory,
		$costs,
		$sse,
	);

	return $orchestrator;
}

// ─── Feature Flag Detection ───────────────────────────────────────────

/**
 * Determine whether the OOS engine should handle the current request.
 *
 * Checks in order:
 *  1. X-WP-MCP-AI-Engine: oos header
 *  2. ?engine=oos query parameter
 *  3. WP_MCP_AI_OOS_ENGINE constant
 *
 * @return bool
 */
function wp_mcp_ai_oos_engine_enabled(): bool {
	if ( defined( 'WP_MCP_AI_OOS_ENGINE' ) && WP_MCP_AI_OOS_ENGINE ) {
		return true;
	}

	if ( isset( $_SERVER['HTTP_X_WP_MCP_AI_ENGINE'] )
		&& 'oos' === $_SERVER['HTTP_X_WP_MCP_AI_ENGINE']
	) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['engine'] ) && 'oos' === $_GET['engine'] ) {
		return true;
	}

	return false;
}

// ─── Bootstrap Hook ───────────────────────────────────────────────────

add_action(
	'wp_mcp_ai_bootstrapped',
	function () {
		// Pre-warm the orchestrator so it's ready when a chat request arrives.
		wp_mcp_ai_oos_orchestrator();
	},
	12
);
